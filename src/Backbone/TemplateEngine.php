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
            if(!$this->document = SimpleHtmlDom::string($_string))
            {
                return $_string;
            }

            $has_parsed_loops = false;

            /**
             *  Meta (SEO)
             */
            $meta = Node::get("meta") ?: array('title' => null, 'description' => null);

            foreach($meta AS $tag => $data)
            {
                $element = ($tag == "title") ? $tag : "meta[name='".$tag."']";

                if($element = $this->document->find($element, 0) ?? $this->document->find("meta[property='".$tag."']"))
                {
                    $this->parseElement($element);
                }
            }

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
                        $nodes[$key][] = array(
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
                    $nodes[$key] = array(
                        'type'         => $this->identifyNodeType($element->attr['type'] ?? null),
                        'properties'   => $this->getPropertiesFromNodeType($element->attr),
                        'identifier'   => $key,
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
            elseif($node instanceof Node && empty($node->loop()))
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

                foreach($node AS $key => $n) // $n == $node
                {
                    /**
                     *  Break out the Loop from the tree
                     */
                    $loop_element_node = SimpleHtmlDom::string($loop_element->innertext());

                    foreach($loop_element_node->find("node[name]") AS $element)
                    {
                        $this->parseElement($element, $loop_element->attr['name'], $key);
                    }

                    $new_nodes[] = str_replace("{{key}}", $key, (string) $loop_element_node);
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
            $is_meta = (in_array($element->tag, ["meta", "title"]) || str_starts_with($element->attr['name'], "meta.")) ? true : false;

            if($is_meta)
            {
                $name = (isset($element->attr['name']) && str_starts_with($element->attr['name'], "meta.")) ? $element->attr['name'] : "meta." . ($element->attr['name'] ?? $element->tag);
            }
            else
            {
                $name = $element->attr['alias'] ?? $element->attr['name'];
            }

            $identifier = (!empty($parent)) ? $parent . "." . $key . "." . $name : $name;

            if(!empty($key))
            {
                $element->attr['key'] = $key;
            }

            if($is_meta)
            {
                if($element->tag == "title")
                {
                    list($element->innertext, $stored) = $this->handle($identifier, $element->attr, $element->innertext);
                }
                else
                {
                    list($element->content, $stored) = $this->handle($identifier, $element->attr, $element->innertext);
                }
            }
            elseif(isset($element->attr['type']) && $element->attr['type'] == "route")
            {
                list($route_data, $stored) = $this->handle($identifier, $element->attr, "#");
                
                $element->href = $route_data[0];
                $element->innertext = $route_data[1] ?? $element->innertext;
                
                $element->tag = "a";
            }
            else
            {
                if(isset($element->attr['as']))
                {
                    list($element->innertext, $stored) = $this->handle($identifier, $element->attr, $element->innertext);
                    $element->removeChild($element);
                }
                else
                {
                    list($element->outertext, $stored) = $this->handle($identifier, $element->attr, $element->innertext);
                }
            }

            if(isset($element->attr['as']))
            {
                $element->tag = $element->attr['as'];
                unset($element->attr['as']);
            }

            if(!empty($element->attr))
            {
                $element->attr['name'] = null;
            }
            
            if($stored)
            {
                $element->attr['type'] = null;
            }
            else //if(empty($key)) // $key == indicates this is from a loop item
            {
                if(!empty($parent))
                {
                    if(!isset($this->elements[self::ELEMENT_UNIDENTIFIED][$parent]))
                    {
                        $this->elements[self::ELEMENT_UNIDENTIFIED][$parent] = array();
                    }

                    $this->elements[self::ELEMENT_UNIDENTIFIED][$parent][$name] = $element;
                }
                else
                {
                    $this->elements[self::ELEMENT_UNIDENTIFIED][$name] = $element;
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
            return match($_type)
            {
                "image", "picture"  => Node::TYPE_IMAGE,
                "loop"              => Node::TYPE_LOOP,
                "wysiwyg", "html"   => Node::TYPE_HTML,
                "bool", "boolean"   => Node::TYPE_BOOLEAN,
                "route"             => Node::TYPE_ROUTE,
                "textarea"          => Node::TYPE_TEXTAREA,
                default             => Node::TYPE_TEXT
            };
		}

		private function handle($identifier, $properties, $fallback)
		{
            if(!$node = Node::get($identifier))
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

            return match($properties['type'])
            {
                "text", "html", "textarea", "meta" => array($node->text($properties), true),
                "image"     => array($node->image($properties['width'] ?? null, $properties['height'] ?? null), true),
                "picture"   => array($node->picture($properties['width'] ?? null, $properties['height'] ?? null), true),
                "route"     => array($node->route($properties), true),
                default     => array($fallback, false)
            };
		}
	}
?>