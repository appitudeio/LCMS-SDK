<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Core\Node;
    use LCMS\Core\NodeType;
    use LCMS\Util\SimpleHtmlDom;
    use LCMS\Util\HtmlNode;

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
            foreach($this->document->find("node") AS $element)
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

            $buildElement = (fn($el, $key) => array_filter(array(
                'type'         => $this->identifyNodeType($el)->value,
                'properties'   => $this->getPropertiesFromNode($el),
                'identifier'   => $key,
                'content'      => $el->attr['src'] ?? (string) $el->innertext ?? "", // Fallback text from document
                'global'       => (array_key_exists("global", $el->attr)) ? true : null
            )));

			foreach($this->elements[self::ELEMENT_UNIDENTIFIED] AS $key => $element)
			{
                if($element instanceof HtmlNode)
                {
                    $nodes[$key] = $buildElement($element, $key);
                }
                elseif(is_array($element))
                {
                    if(!isset($nodes[$key]))
                    {
                        $nodes[$key] = array();
                    }

                    foreach($element AS $name => $e)
                    {
                        if($e instanceof HtmlNode)
                        {
                            $nodes[$key][] = $buildElement($e, $e->attr['name'] ?? $name);
                        }
                        else
                        {
                            $nodes[$key][] = $e;
                        }
                    }
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
        private function handleLoop(object $_loop_element): void
        {
            // Items found, let's put them into the loop (Merge with nodes) OR If empty, maybe the elements are unidentified
            if((false === $node = Node::get($_loop_element->attr['name'])) || ($node instanceof Node && empty($node->loop())))
            {
                $this->elements[self::ELEMENT_UNIDENTIFIED][$_loop_element->attr['name']] = array();

                foreach($_loop_element->find("node[name]") AS $element)
                {
                    $this->elements[self::ELEMENT_UNIDENTIFIED][$_loop_element->attr['name']][] = $element;
                }

                $_loop_element->remove();
            }
            else
            {
                $new_nodes = array();

                $i = 1;
            
                foreach($node AS $key => $item) // $n == $node
                {
                    // Break out the Loop from the tree
                    $loop_element_node = SimpleHtmlDom::string($_loop_element->innertext());

                    foreach($loop_element_node->find("node") AS $element)
                    {
                        $this->parseElement($element, $_loop_element->attr['name'], $key);
                    }

                    $new_nodes[] = str_replace(["{{key}}", "{{int}}"], [$key, $i], (string) $loop_element_node);

                    $i++;
                }

                /**
                 *  Replace the old <loop> with new html
                 */
                $_loop_element->outertext = implode("", $new_nodes);

                unset($new_nodes);
            }
        }

        /**
         *  Handles \LCMS\Util\SimpleHtmlDom subclass HtmlNode
         *  
         *  @return HtmlNode with modified content
         */
        private function parseElement(object $_element, string | null $_parent = null, mixed $_key = null): void
        {
            $is_meta = (in_array($_element->tag, ["meta", "title"]) || (isset($_element->attr['name']) && str_starts_with($_element->attr['name'], "meta."))) ? true : false;

            if($is_meta)
            {
                $name = (isset($_element->attr['name']) && str_starts_with($_element->attr['name'], "meta.")) ? $_element->attr['name'] : "meta." . ($_element->attr['name'] ?? $_element->tag);
            }

            if($name ??= $_element->attr['alias'] ?? $_element->attr['name'] ?? false)
            {
                $identifier = (!empty($_parent)) ? $_parent . "." . $_key . "." . $name : $name;
            }

            if(!empty($_key))
            {
                $_element->attr['key'] = $_key;
            }

            if($is_meta)
            {
                if($_element->tag == "title")
                {
                    list($_element->innertext, $stored) = $this->handle($identifier ?? null, $_element->attr, $_element->innertext);
                }
                else
                {
                    list($_element->content, $stored) = $this->handle($identifier ?? null, $_element->attr, $_element->innertext);
                }
            }
            elseif(($type = $_element->attr['type'] ?? $_element->attr['as'] ?? false) && in_array($type, ['route', 'a']))
            {
                list($href, $stored) = $this->handle($identifier ?? null, $_element->attr, $_element->innertext ?? "");

                if(isset($href->asArray()['properties']) && !empty($href->asArray()['properties']) && $props = array_filter($href->asArray()['properties'], fn($k, $v) => !in_array($k, ['as', 'name']) && $_element->$k != $v, ARRAY_FILTER_USE_BOTH))
                {
                    foreach($props AS $tag => $value)
                    {
                        $_element->$tag = $value;
                    }
                }

                $tag = ($_element->hasChildNodes()) ? "content" : "innertext";

                $_element->$tag = ($type == "route") ? (string) $href : ((!empty((string) $href->prop("title")) ? (string) $href->prop("title") : (string) $href->prop("content")));
                $_element->attr['as'] = "a";
            }
            elseif(($type = $_element->attr['type'] ?? $_element->attr['as'] ?? false) && in_array($type, ['img', 'image', 'picture']))
            {
                list($image, $stored) = $this->handle($identifier ?? null, $_element->attr, $_element->attr['src'] ?? $_element->innertext);
                $_element->outertext = (string) $image;
            }
            else
            {
                /**
                 *  Text HtmlNodes may have children, so we remove them
                 */
                if(isset($_element->attr['as']))
                {
                    list($_element->innertext, $stored) = $this->handle($identifier ?? null, $_element->attr, $_element->innertext);
                   
                    if($_element->hasChildNodes())
                    {
                        array_walk($_element->nodes, fn($e) => $_element->removeChild($e));
                    }
                }
                else
                {
                    list($_element->outertext, $stored) = $this->handle($identifier ?? null, $_element->attr, $_element->innertext);
                }
            }

            if(isset($_element->attr['as']))
            {
                $_element->tag = $_element->attr['as'];
                unset($_element->attr['as']);
            }

            if(isset($_element->attr['global']))
            {
                $_element->attr['global'] = null;
            }

            if(!empty($_element->attr))
            {
                $_element->attr['name'] = null;
            }

            if($stored)
            {
                $_element->attr['type'] = null;
            }
            elseif($name) //if(empty($_key)) // $key == indicates this is from a loop item
            {
                if(!empty($_parent))
                {
                    if(!isset($this->elements[self::ELEMENT_UNIDENTIFIED][$_parent]))
                    {
                        $this->elements[self::ELEMENT_UNIDENTIFIED][$_parent] = array();
                    }

                    $this->elements[self::ELEMENT_UNIDENTIFIED][$_parent][$name] = $_element;
                }
                else
                {
                    $this->elements[self::ELEMENT_UNIDENTIFIED][$name] = $_element;
                }
            }
        }        

        private function getPropertiesFromNode(object $_element): null | array
        {
            $type = $this->identifyNodeType($_element)->value; //->attr['type'] ?? (($_element->tag == "node") ? $_element->attr['as'] ?? null : null));

            if(!isset(Node::getInstance()->type_properties[$type]))
            {
                return null;
            }

            $self = array();

            foreach(Node::getInstance()->type_properties[$type] AS $k => $v)
            {
                $self[$k] = $_element->attr[$k] ?? $v;
            }

            return $self;
        }

		private function identifyNodeType(object $_element): NodeType
		{
            // If no 'type' found, check if it contains html tags, then it's a wysiwyg
            if(!$type = $_element->attr['type'] ?? (($_element->tag == "node") ? $_element->attr['as'] ?? null : $_element->tag ?? null))
            {
                // HDOM_TYPE_ELEMENT == 1
                if($_element->nodes && array_filter($_element->nodes, fn($n) => $n->nodetype == 1))
                {
                    $type = "wysiwyg";
                }   
            }
            
            return match($type)
            {
                "image", "picture", "img" => NodeType::IMAGE,
                "background"        => NodeType::BACKGROUND,
                "loop"              => NodeType::LOOP,
                "wysiwyg", "html"   => NodeType::HTML,
                "bool", "boolean"   => NodeType::BOOLEAN,
                "route"             => NodeType::ROUTE,
                "a"                 => NodeType::HYPERLINK,
                "textarea"          => NodeType::TEXTAREA,
                default             => NodeType::TEXT
            };
		}

		private function handle(?string $_identifier, ?array $_properties, string $_fallback): array | string
		{
            $stored = true;
            $type = $_properties['type'] ?? $_properties['as'] ?? null;

            if(empty($_identifier) || !$node = Node::get($_identifier))
            {
                unset($_properties['as'], $_properties['name']);
                $props = (!empty($_properties)) ? array('properties' => $_properties) : array();

                $node = Node::createNodeObject($_identifier, array('content' => $_fallback) + $props);
                $stored = false;
            }

			if(isset($node->asArray()['properties']) && !empty($node->asArray()['properties']))
			{
				$_properties = array_merge($_properties, array_filter($node->asArray()['properties']));
			}

            if(empty($type) || !in_array($type, ['text', 'html', 'textarea', 'meta', 'image', 'picture', 'route', 'a', 'img']))
            {
                return array($node->text($_properties), $stored);
            }

            return match($type)
            {
                "text", "html", "textarea", "meta" => array($node->text($_properties), $stored),
                "image", "img"  => array($node->image($_properties['width'] ?? null, $_properties['height'] ?? null), $stored),
                "picture"       => array($node->picture($_properties['width'] ?? null, $_properties['height'] ?? null), $stored),
                "background"    => array($node->background($_properties['width'] ?? null, $_properties['height'] ?? null), $stored),
                "route"         => array($node->route($_properties), $stored),
                "a"             => array($node->href($_properties), $stored),
                default         => array($_fallback, false)
            };
		}
	}
?>