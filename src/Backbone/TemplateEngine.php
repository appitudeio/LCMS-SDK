<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Core\Node;
    use LCMS\Utils\SimpleHtmlDom;

    use \Closure;
	use \Exception;

	class TemplateEngine
	{
        const ELEMENT_UNIDENTIFIED  = "unidentified";
        const ELEMENT_IDENTIFIED    = "identified";

        private $elements;
        private $document;

		public function parse(String $_string, Closure $_unidentfied_nodes_collector = null): SimpleHtmlDom | String
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
                    if(is_array($element))
                    {
                        array_walk($element, fn($e) => (!empty($e->attr) && empty($e->attr['content'])) ? $this->parseElement($e) : null);
                    }
                    elseif(empty($element->attr) || empty($element->attr['content']))
                    {
                        $this->parseElement($element);
                    }
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

            $buildElement = (fn($el, $key) => array(
                'type'         => $this->identifyNodeType($el->attr['type'] ?? null),
                'properties'   => $this->getPropertiesFromNodeType($el->attr),
                'identifier'   => $key,
                'content'      => $el->attr['href'] ?? $el->attr['src'] ?? $el->innertext ?? null, // Fallback text from document
                'global'       => $el->attr['global'] ?? false
            ));

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
                        $nodes[$key][] = $buildElement($e, $e->attr['name']);
                    }
                }
                else
                {
                    $nodes[$key] = $buildElement($element, $key);
                }
			}

            /**
             *   The Collector will decide what to do with the nodes.
             *      - Probably store them in ini-file or to DB
             */
            $_unidentfied_nodes_collector($nodes);

			return $this->document;
		}

        /**
         *  Handles \LCMS\Utils\SimpleHtmlDom subclass HtmlNode
         */
        private function handleLoop(Object $loop_element): Void
        {
            // Items found, let's put them into the loop (Merge with nodes)
            if(false === $node = Node::get($loop_element->attr['name']))
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

        /**
         *  Handles \LCMS\Utils\SimpleHtmlDom subclass HtmlNode
         *  
         *  @return HtmlNode with modified content
         */
        private function parseElement(Object $element, String $parent = null, Int $key = null): Void
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

        private function getPropertiesFromNodeType(Array $_properties): Null | Array
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

		private function identifyNodeType(String $_type = null): String
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

		private function handle(String $identifier, Array $properties, String $fallback): Array | String
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

            return match($properties['type'] ?? "")
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