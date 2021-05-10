<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Core\Node;
    use LCMS\Utils\SimpleHtmlDom;

	use \Exception;

	class TemplateEngine
	{
        const ELEMENT_UNIDENTIFIED  = "unidentified";
        const ELEMENT_IDENTIFIED    = "identified";

		private $nodes;
        private $elements;
        private $document;

		public function parse($_string, $_unidentfied_nodes_collector = null)
		{
            $has_parsed_loops = false;
			$this->document = SimpleHtmlDom::string($_string);

            /**
             *  Try to find Loops
             */
            foreach($this->document->find("loop[name]") AS $loop_element)
            {
                $this->handleLoop($loop_element);
                $has_parsed_loops = true;
            }

            if($has_parsed_loops)
            {
                // If we've parsed loops, let's refresh the document
                $this->document->load($this->document->outertext);
            }

            /**
             *  Now parse what's left
             */
            foreach($this->document->find("node[name]") AS $element)
            {
                $this->parseElement($element);
            }

            /**
             *  Any unidentified elements found?
             */
            if(!isset($this->elements[self::ELEMENT_UNIDENTIFIED]) || empty($_unidentfied_nodes_collector))
            {
                return $this->document;
            }

			$nodes = array();

			foreach($this->elements[self::ELEMENT_UNIDENTIFIED] AS $key => $element)
			{
                if(is_array($element))
                {
                    if(!isset($nodes[$key]))
                    {
                        $nodes[$key] = array();
                    }

                    foreach($element AS $e)
                    {                        
                        $nodes[$key][$e->attr['name']] = array(
                            'type'         => $this->identifyNodeType($e->attr['type'] ?? null),
                            'properties'   => $this->getPropertiesFromNodeType($e->attr),
                            'identifier'   => $e->attr['name'],
                            'content'      => $e->attr['href'] ?? ($e->innertext ?? null), // Fallback text from document
                            'global'       => $e->attr['global'] ?? false
                        );
                    }
                }
                else
                {
                    $nodes[$element->attr['name']] = array(
                        'type'         => $this->identifyNodeType($element->attr['type'] ?? null),
                        'properties'   => $this->getPropertiesFromNodeType($element->attr),
                        'identifier'   => $element->attr['name'],
                        'content'      => $element->attr['href'] ?? ($element->innertext ?? null), // Fallback text from document
                        'global'       => $element->attr['global'] ?? false
                    );
                }
			}

            $_unidentfied_nodes_collector($nodes); // Send to collector

			return $this->document;
		}

        private function handleLoop($loop_element)
        {
            // Check if this Loop exists
            $node = Node::get($loop_element->attr['name']);

            // Items found, let's put them into the loop (Merge with nodes)
            if(!$node)
            {
                $this->elements[self::ELEMENT_UNIDENTIFIED][$loop_element->attr['name']] = array();

                foreach($loop_element->find("node[name]") AS $element)
                {
                    $this->elements[self::ELEMENT_UNIDENTIFIED][$loop_element->attr['name']][] = $element;
                }

                $loop_element->remove();
            }
            elseif(empty($node->loop()))
            {
                // If empty, maybe the elements are unidentified
                $this->elements[self::ELEMENT_UNIDENTIFIED][$loop_element->attr['name']] = array();

                foreach($loop_element->find("node[name]") AS $element)
                {
                    $this->elements[self::ELEMENT_UNIDENTIFIED][$loop_element->attr['name']][] = $element;
                }

                $loop_element->remove();
            }
            else
            {
                $new_nodes = array();

                foreach($node->loop() AS $key => $item)
                {
                    /**
                     *  Break out the Loop from the tree
                     */
                    $loop_element_node = SimpleHtmlDom::string($loop_element->innertext());

                    foreach($loop_element_node->find("node[name]") AS $element)
                    {
                        $this->parseElement($element, $loop_element->attr['name'], $key);
                    }

                    $new_nodes[] = $loop_element_node;
                }

                /**
                 *  Replace the old <loop> with new html
                 */
                $loop_element->outertext = implode("", $new_nodes);

                unset($new_nodes);
            }
        }

        private function parseElement($element, $parent = null, $key = null)
        {
            $identifier = (!empty($parent)) ? $parent . "." . $key . "." . $element->attr['name'] : $element->attr['name'];

            if(isset($element->attr['type']) && $element->attr['type'] == "route")
            {
                list($element->href, $stored) = $this->handle($identifier, $element->attr, "#");

                $element->tag = "a";
            }
            else
            {
                list($element->outertext, $stored) = $this->handle($identifier, $element->attr, $element->innertext);
            }

            if(isset($element->attr['as']))
            {
                $element->tag = $element->attr['as'];
            }

            if($stored)
            {
                $element->attr['type'] = null;
            }
            else
            {
                if(!empty($parent))
                {
                    if(!isset($this->elements[self::ELEMENT_UNIDENTIFIED][$parent]))
                    {
                        $this->elements[self::ELEMENT_UNIDENTIFIED][$parent] = array();
                    }

                    $this->elements[self::ELEMENT_UNIDENTIFIED][$parent][$element->attr['name']] = $element;
                }
                else
                {
                    $this->elements[self::ELEMENT_UNIDENTIFIED][$element->attr['name']] = $element;
                }
            }
        }        

        private function getPropertiesFromNodeType($_properties)
        {
            $type = $this->identifyNodeType($_properties['type'] ?? null);

            if(!in_array($type, [Node::TYPE_IMAGE])) //, Node::TYPE_ROUTE]))
            {
                return null;
            }

            $self = array();

            foreach(Node::$type_properties[$type] AS $k => $v)
            {
                $self[$k] = $_properties[$k] ?? $v;
            }

           return $self;
        }

		private function identifyNodeType($_type = null)
		{
            if(empty($_type))
            {
                return Node::TYPE_TEXT;
            }

            switch($_type)
            {
                case "image":
                case "picture":
                    return Node::TYPE_IMAGE;
                break;

                case "loop":
                    return Node::TYPE_LOOP;
                break;

                case "wysiwyg":
                case "html":
                    return Node::TYPE_HTML;
                break;

                case "bool":
                case "boolean":
                    return Node::TYPE_BOOLEAN;
                break;

                case "route":
                    return Node::TYPE_ROUTE;
                break;

                case "textarea":
                    return Node::TYPE_TEXTAREA;
                break;

                default:
                    return Node::TYPE_TEXT;
            }
		}

		private function handle($identifier, $properties, $fallback)
		{
			$node = Node::get($identifier);

            if(!$node)
            {
                return array($fallback, false);
            }

            $properties = $properties ?? array();

			if(isset($node->asArray()['properties']) && !empty($node->asArray()['properties']))
			{
				$properties = array_merge($properties, $node->asArray()['properties']);
			}

            if(!isset($properties['type']))
            {
                return array($node->text($properties), true);
            }

			switch($properties['type'])
			{
				case "text":
                case "html":
                case "textarea":
					return array($node->text($properties), true);
				break;

				case "image":
					return array($node->image($properties['width'] ?? null, $properties['height']?? null), true);
				break;

				case "picture":
					return array($node->picture($properties['width'] ?? null, $properties['height']?? null), true);
				break;

                case "route":
                    return array($node->route(), true);
                break;

                default:
                    return array($fallback, false);
			}
		}
	}
?>