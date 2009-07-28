<?php
/**
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */

//TODO os:repeat (and <foo repeat="" var="">) has a var="foo" param that hasn't been implmemented yet
//TODO for some reason the OSML spec stats you have to <Require feature="osml"> to use the os:Name etc tags yet no such feature exists, and for the code path's here it's not required at all..
//TODO remove the os-templates javascript if all the templates are rendered on the server (saves many Kb's in gadget size)
//TODO support for OSML tag functions and extensions (os:render, osx:flash, osx:parsejson, etc)
//TODO support os-template tags on OSML tags, ie this should work: <os:Html if="${Foo}" repeat="${Bar}" />

require_once 'ExpressionParser.php';

class TemplateParser {
  private $dataContext;
  private $templateLibrary;

  public function dumpNode($node, $function) {
    $doc = new DOMDocument(null, 'utf-8');
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    $doc->strictErrorChecking = false;
    $doc->recover = false;
    $doc->resolveExternals = false;
    if (! $newNode = @$doc->importNode($node, false)) {
      echo "[Invalid node, dump failed]<br><br>";
      return;
    }
    $doc->appendChild($newNode);
    echo "<b>$function (" . get_class($node) . "):</b><br>" . htmlentities(str_replace('<?xml version="" encoding="utf-8"?>', '', $doc->saveXML()) . "\n") . "<br><br>";
  }

  /**
   * Processes an os-template
   *
   * @param string $template
   */
  public function process(DOMnode &$osTemplate, $dataContext, $templateLibrary) {
    $this->setDataContext($dataContext);
    $this->templateLibrary = $templateLibrary;
    if ($osTemplate instanceof DOMElement) {
      if (($removeNode = $this->parseNode($osTemplate)) !== false) {
        $removeNode->parentNode->removeChild($removeNode);
      }
    }
  }

  /**
   * Sets and initializes the data context to use while processing the template
   *
   * @param array $dataContext
   */
  private function setDataContext($dataContext) {
    $this->dataContext = array();
    $this->dataContext['Top'] = $dataContext;
    $this->dataContext['Cur'] = array();
    $this->dataContext['My'] = array();
    $this->dataContext['Context'] = array('UniqueId' => uniqid());
  }

  public function parseNode(DOMNode &$node) {
    $removeNode = false;
    if ($node instanceof DOMText) {
      if (! $node->isWhitespaceInElementContent() && ! empty($node->nodeValue)) {
        $this->parseNodeText($node);
      }
    } else {
      $tagName = isset($node->tagName) ? $node->tagName : '';
      if (substr($tagName, 0, 3) == 'os:' || substr($tagName, 0, 4) == 'osx:') {
        $removeNode = $this->parseOsmlNode($node);
      } elseif ($this->templateLibrary->hasTemplate($tagName)) {
        // the tag name refers to an existing template (myapp:EmployeeCard type naming)
        // the extra check on the : character is to make sure this is a name spaced custom tag and not some one trying to override basic html tags (br, img, etc)
        $this->parseLibrary($tagName, $node);
      } else {
        $removeNode = $this->parseNodeAttributes($node);
      }
    }
    return is_object($removeNode) ? $removeNode : false;
  }

  /**
   * Misc function that maps the node's attributes to a key => value array
   * and results any expressions to actual values
   *
   * @param DOMElement $node
   * @return array
   */
  private function nodeAttributesToScope(DOMElement &$node) {
    $myContext = array();
    if ($node->hasAttributes()) {
      foreach ($node->attributes as $attr) {
        if (strpos($attr->value, '${') !== false) {
          // attribute value contains an expression
          $expressions = array();
          preg_match_all('/(\$\{)(.*)(\})/imsxU', $attr->value, $expressions);
          for ($i = 0; $i < count($expressions[0]); $i ++) {
            $expression = $expressions[2][$i];
            $myContext[$attr->name] = ExpressionParser::evaluate($expression, $this->dataContext);
          }
        } else {
          // plain old string
          $myContext[$attr->name] = trim($attr->value);
        }
      }
    }
    return $myContext;
  }

  /**
   * Parses the specified template library
   *
   * @param string $tagName
   * @param DOMNode $node
   */
  private function parseLibrary($tagName, DOMNode &$node) {
    $myContext = $this->nodeAttributesToScope($node);
    // Parse the template library (store the My scope since this could be a nested call)
    $previousMy = $this->dataContext['My'];
    $this->dataContext['My'] = $myContext;
    $ret = $this->templateLibrary->parseTemplate($tagName, $this);
    $this->dataContext['My'] = $previousMy;
    if ($ret) {
      // And replace the node with the parsed output
      $ownerDocument = $node->ownerDocument;
      foreach ($ret->childNodes as $childNode) {
        if ($childNode) {
          $importedNode = $ownerDocument->importNode($childNode, true);
          $node->parentNode->insertBefore($importedNode, $node);
        }
      }
      $node->parentNode->removeChild($node);
    }
  }

  private function parseNodeText(DOMText &$node) {
    if (strpos($node->nodeValue, '${') !== false) {
      $expressions = array();
      preg_match_all('/(\$\{)(.*)(\})/imsxU', $node->wholeText, $expressions);
      for ($i = 0; $i < count($expressions[0]); $i ++) {
        $toReplace = $expressions[0][$i];
        $expression = $expressions[2][$i];
        $expressionResult = ExpressionParser::evaluate($expression, $this->dataContext);
        $stringVal = htmlentities(ExpressionParser::stringValue($expressionResult), ENT_QUOTES, 'UTF-8');
        $node->nodeValue = str_replace($toReplace, $stringVal, $node->nodeValue);
      }
    }
  }

  private function parseNodeAttributes(DOMNode &$node) {
    if ($node->hasAttributes()) {
      foreach ($node->attributes as $attr) {
        if (strpos($attr->value, '${') !== false) {
          $expressions = array();
          preg_match_all('/(\$\{)(.*)(\})/imsxU', $attr->value, $expressions);
          for ($i = 0; $i < count($expressions[0]); $i ++) {
            $toReplace = $expressions[0][$i];
            $expression = $expressions[2][$i];
            $expressionResult = ExpressionParser::evaluate($expression, $this->dataContext);
            switch (strtolower($attr->name)) {

              case 'repeat':
                // Can only loop if the result of the expression was an array
                if (! is_array($expressionResult)) {
                  throw new ExpressionException("Can't repeat on a singular var");
                }
                // Make sure the repeat variable doesn't show up in the cloned nodes (otherwise it would infinit recurse on this->parseNode())
                $node->removeAttribute('repeat');
                // For information on the loop context, see http://opensocial-resources.googlecode.com/svn/spec/0.9/OpenSocial-Templating.xml#rfc.section.10.1
                $this->dataContext['Context']['Count'] = count($expressionResult);
                foreach ($expressionResult as $index => $entry) {
                  $this->dataContext['Cur'] = $entry;
                  $this->dataContext['Context']['Index'] = $index;
                  // Clone this node and it's children
                  $newNode = $node->cloneNode(true);
                  // Append the parsed & expanded node to the parent
                  $newNode = $node->parentNode->insertBefore($newNode, $node);
                  // And parse it (using the global + loop context)
                  $this->parseNode($newNode, true);
                }
                // Remove the original (unparsed) node
                // And remove the loop data context entries
                $this->dataContext['Cur'] = array();
                unset($this->dataContext['Context']['Index']);
                unset($this->dataContext['Context']['Count']);
                return $node;
                break;

              case 'if':
                if (! $expressionResult) {
                  return $node;
                } else {
                  $node->removeAttribute('if');
                }
                break;

              // These special cases that only apply for certain tag types
              case 'selected':
                if ($node->tagName == 'option') {
                  if ($expressionResult) {
                    $node->setAttribute('selected', 'selected');
                  } else {
                    $node->removeAttribute('selected');
                  }
                } else {
                  throw new ExpressionException("Can only use selected on an option tag");
                }
                break;

              case 'checked':
                if ($node->tagName == 'input') {
                  if ($expressionResult) {
                    $node->setAttribute('checked', 'checked');
                  } else {
                    $node->removeAttribute('checked');
                  }
                } else {
                  throw new ExpressionException("Can only use checked on an input tag");
                }
                break;

              case 'disabled':
                $disabledTags = array('input', 'button',
                    'select', 'textarea');
                if (in_array($node->tagName, $disabledTags)) {
                  if ($expressionResult) {
                    $node->setAttribute('disabled', 'disabled');
                  } else {
                    $node->removeAttribute('disabled');
                  }
                } else {
                  throw new ExpressionException("Can only use disabled on input, button, select and textarea tags");
                }
                break;

              default:
                // On non os-template spec attributes, do a simple str_replace with the evaluated value
                $stringVal = htmlentities(ExpressionParser::stringValue($expressionResult), ENT_QUOTES, 'UTF-8');
                $newAttrVal = str_replace($toReplace, $stringVal, $attr->value);
                $node->setAttribute($attr->name, $newAttrVal);
                break;
            }
          }
        }
      }
    }
    // if a repeat attribute was found, don't recurse on it's child nodes, the repeat handling already did that
    if (isset($node->childNodes) && $node->childNodes->length > 0) {
      $removeNodes = array();
      // recursive loop to all this node's children
      foreach ($node->childNodes as $childNode) {
        if (($removeNode = $this->parseNode($childNode)) !== false) {
          $removeNodes[] = $removeNode;
        }
      }
      if (count($removeNodes)) {
        foreach ($removeNodes as $removeNode) {
          $removeNode->parentNode->removeChild($removeNode);
        }
      }

    }
    return false;
  }

  /**
   * Function that handles the os: and osx: tags
   *
   * @param DOMNode $node
   */
  private function parseOsmlNode(DOMNode &$node) {
    $tagName = strtolower($node->tagName);
    switch ($tagName) {

      /****** Control statements ******/

      case 'os:repeat':
        if (! $node->getAttribute('expression')) {
          throw new ExpressionException("Invalid os:Repeat tag, missing expression attribute");
        }
        $expressions = array();
        preg_match_all('/(\$\{)(.*)(\})/imsxU', $node->getAttribute('expression'), $expressions);
        $expression = $expressions[2][0];
        $expressionResult = ExpressionParser::evaluate($expression, $this->dataContext);
        if (! is_array($expressionResult)) {
          throw new ExpressionException("Can't repeat on a singular var");
        }
        // For information on the loop context, see http://opensocial-resources.googlecode.com/svn/spec/0.9/OpenSocial-Templating.xml#rfc.section.10.1
        $this->dataContext['Context']['Count'] = count($expressionResult);
        foreach ($expressionResult as $index => $entry) {
          $this->dataContext['Cur'] = $entry;
          $this->dataContext['Context']['Index'] = $index;
          foreach ($node->childNodes as $childNode) {
            $newNode = $childNode->cloneNode(true);
            $newNode = $node->parentNode->insertBefore($newNode, $node);
            $this->parseNode($newNode);
          }
        }
        $node->parentNode->removeChild($node);
        $this->dataContext['Cur'] = array();
        unset($this->dataContext['Context']['Index']);
        unset($this->dataContext['Context']['Count']);
        break;

      case 'os:if':
        $expressions = array();
        if (! $node->getAttribute('condition')) {
          throw new ExpressionException("Invalid os:If tag, missing condition attribute");
        }
        preg_match_all('/(\$\{)(.*)(\})/imsxU', $node->getAttribute('condition'), $expressions);
        if (! count($expressions[2])) {
          throw new ExpressionException("Invalid os:If tag, missing condition expression");
        }
        $expression = $expressions[2][0];
        $expressionResult = ExpressionParser::evaluate($expression, $this->dataContext);
        if ($expressionResult) {
          foreach ($node->childNodes as $childNode) {
            $newNode = $childNode->cloneNode(true);
            $this->parseNode($newNode);
            $newNode = $node->parentNode->insertBefore($newNode, $node);
          }
        }
        return $node;
        break;

      /****** OSML tags (os: name space) ******/

      case 'os:name':
        $this->parseLibrary('os:Name', $node);
        break;

      case 'os:badge':
        $this->parseLibrary('os:Badge', $node);
        break;

      case 'os:peopleselector':
        $this->parseLibrary('os:PeopleSelector', $node);

        break;

      case 'os:html':
        if (! $node->getAttribute('code')) {
          throw new ExpressionException("Invalid os:Html tag, missing code attribute");
        }
        $node->parentNode->replaceChild($node->ownerDocument->createTextNode($node->getAttribute('code')), $node);
        break;

      case 'os:render':
        break;

      /****** Extension - Tags ******/

      case 'osx:flash':
        break;

      case 'osx:navigatetoapp':
        break;

      case 'osx:navigatetoperson':
        break;

      /****** Extension - Functions ******/

      case 'osx:parsejson':
        break;

      case 'osx:decodebase64':
        break;

      case 'osx:urlencode':
        break;

      case 'osx:urldecode':
        break;
    }
    return false;
  }
}
