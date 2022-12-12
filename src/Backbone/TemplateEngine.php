<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Core\Node;
    use LCMS\Util\SimpleHtmlDom;

    use \Closure;
	use \Exception;

	class TemplateEngine
	{
        const ELEMENT_UNIDENTIFIED  = "unidentified";
        const ELEMENT_IDENTIFIED    = "identified";

        private $elements;
        private $document;

		public function parse(string $_string, Closure $_unidentfied_nodes_collector = null): SimpleHtmlDom | string
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
                'type'         => $this->identifyNodeType($el->attr['type'] ?? $el->tag ?? null),
                'properties'   => $this->getPropertiesFromNode($el),
                'identifier'   => $key,
                'content'      => $el->attr['src'] ?? (string) $el->innertext ?? "", // Fallback text from document
                'global'       => array_key_exists("global", $el->attr)
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
         *  Handles \LCMS\Util\SimpleHtmlDom subclass HtmlNode
         */
        private function handleLoop(object $loop_element): void
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
         *  Handles \LCMS\Util\SimpleHtmlDom subclass HtmlNode
         *  
         *  @return HtmlNode with modified content
         */
        private function parseElement(object $element, string $parent = null, int $key = null): void
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
            elseif((isset($element->attr['type']) && in_array($element->attr['type'], ['route', 'a'])) || (isset($element->attr['as']) && in_array($element->attr['as'], ['route', 'a'])))
            {
                list($href, $stored) = $this->handle($identifier, $element->attr, $element->innertext ?? "");

                if(isset($href->asArray()['properties']) && !empty($href->asArray()['properties']))
                {
                    $excluded_data = ['as', 'name'];

                    foreach(array_filter($href->asArray()['properties'], fn($d) => !in_array($d, $excluded_data), ARRAY_FILTER_USE_KEY) AS $tag => $value)
                    {
                        if($element->$tag != $value)
                        {
                            $element->$tag = $value;
                        }
                    }
                }

                $element->innertext = (string) $href;
                $element->attr['as'] = "a";
            }
            elseif((isset($element->attr['type']) && in_array($element->attr['type'], ['img', 'image', 'picture'])) || (isset($element->attr['as']) && in_array($element->attr['as'], ['img', 'image', 'picture'])))
            {
                list($image, $stored) = $this->handle($identifier, $element->attr, $element->attr['src'] ?? $element->innertext);
                $element->outertext = (string) $image;
            }
            else
            {
                /**
                 *  Text HtmlNodes may have siblings, so we remove them
                 */
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

            if(isset($element->attr['global']))
            {
                $element->attr['global'] = null;
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

        private function getPropertiesFromNode(object $_element): null | array
        {
            $type = $this->identifyNodeType($_element->attr['type'] ?? $_element->tag ?? null);

            if(!in_array($type, [Node::TYPE_IMAGE, Node::TYPE_HYPERLINK])) //, Node::TYPE_ROUTE]))
            {
                return null;
            }

            $self = array();

            foreach(Node::$type_properties[$type] AS $k => $v)
            {
                $self[$k] = $_element->attr[$k] ?? $v;
            }

           return $self;
        }

		private function identifyNodeType(string $_type = null): string
		{
            return match($_type)
            {
                "image", "picture", "img" => Node::TYPE_IMAGE,
                "background"        => Node::TYPE_BACKGROUND,
                "loop"              => Node::TYPE_LOOP,
                "wysiwyg", "html"   => Node::TYPE_HTML,
                "bool", "boolean"   => Node::TYPE_BOOLEAN,
                "route"             => Node::TYPE_ROUTE,
                "a"                 => Node::TYPE_HYPERLINK,
                "textarea"          => Node::TYPE_TEXTAREA,
                default             => Node::TYPE_TEXT
            };
		}

		private function handle(string $identifier, array $properties, string $fallback): array | string
		{
            $stored = true;
            $type = $properties['type'] ?? $properties['as'] ?? null;

            if(!$node = Node::get($identifier))
            {
                unset($properties['as'], $properties['name']);
                $props = (!empty($properties)) ? array('properties' => $properties) : array();

                $node = Node::createNodeObject(array('content' => $fallback) + $props);
                $stored = false;
            }

			if(isset($node->asArray()['properties']) && !empty($node->asArray()['properties']))
			{
				$properties = array_merge($properties, array_filter($node->asArray()['properties']));
			}

            if(!in_array($type, ['text', 'html', 'textarea', 'meta', 'image', 'picture', 'route', 'a', 'img']))
            {
                return array($node->text($properties), $stored);
            }

            return match($type)
            {
                "text", "html", "textarea", "meta" => array($node->text($properties), $stored),
                "image", "img"  => array($node->image($properties['width'] ?? null, $properties['height'] ?? null), $stored),
                "picture"       => array($node->picture($properties['width'] ?? null, $properties['height'] ?? null), $stored),
                "background"    => array($node->background($properties['width'] ?? null, $properties['height'] ?? null), $stored),
                "route"         => array($node->route($properties), $stored),
                "a"             => array($node->href($properties), $stored),
                default         => array($fallback, false)
            };
		}
	}
?>