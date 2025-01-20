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
    
	class TemplateEngine
	{
        const ELEMENT_UNIDENTIFIED  = "unidentified";
        const ELEMENT_IDENTIFIED    = "identified";

        private $elements;
        private $document;

		public function parse(string $_string, ?Closure $_unidentified_nodes_collector = null): SimpleHtmlDom|string
		{
            if(!$this->document = SimpleHtmlDom::string($_string))
            {
                return $_string;
            }

            $this->parseMeta();
            $this->parseLoops();
            $this->parseNodes();

            if ($_unidentified_nodes_collector) 
            {
                $unidentifiedNodes = $this->collectUnidentifiedNodes();
                $_unidentified_nodes_collector($unidentifiedNodes);
            }

            return $this->document;
		}

        private function parseMeta(): void
        {
            $meta = Node::get("meta") ?? ['title' => null, 'description' => null];

            foreach ($meta AS $tag => $data) 
            {
                $element_selector = $tag === "title" ? $tag : "meta[name='$tag']";

                if ($element = $this->document->find($element_selector, 0) ?? $this->document->find("meta[property='$tag']")) 
                {
                    $this->processMetaElement($element);
                }
            }
        }

        private function processMetaElement(HtmlNode|array $element): void
        {
            if (is_array($element)) 
            {
                array_walk($element, [$this, 'parseElement']);
            } 
            elseif (empty($element->attr['content'])) 
            {
                $this->parseElement($element);
            }
        }
    
        private function parseLoops(): void
        {
            $has_parsed_loops = false;

            foreach ($this->document->find("loop[name]") AS $loopElement) 
            {
                $this->handleLoop($loopElement);
                $has_parsed_loops = true;
            }

            if ($has_parsed_loops) 
            {
                // Refresh the document after loops are parsed
                $this->document->load($this->document->outertext);
            }
        }

        private function parseNodes(): void
        {
            foreach ($this->document->find("node") AS $element) 
            {
                $this->parseElement($element);
            }
        }

        private function collectUnidentifiedNodes(): array
        {
            if (empty($this->elements[self::ELEMENT_UNIDENTIFIED])) 
            {
                return [];
            }

            $unidentified_nodes = [];

            foreach ($this->elements[self::ELEMENT_UNIDENTIFIED] AS $key => $element) 
            {
                $unidentified_nodes[$key] = $this->buildUnidentifiedNode($element, $key);
            }

            return $unidentified_nodes;
        }

        private function buildUnidentifiedNode($element, $key): array
        {
            if ($element instanceof HtmlNode) 
            {
                return $this->extractNodeProperties($element, $key);
            }

            if (is_array($element)) 
            {
                $node_group = array_map(fn ($e, $name) =>
                    $e instanceof HtmlNode
                    ? $this->extractNodeProperties($e, $e->attr['name'] ?? $name)
                    : $e
                , $element, array_keys($element));

                return $node_group;
            }

            return [];
        }

        private function extractNodeProperties(HtmlNode $element, string $key): array
        {
            return array_filter([
                'type'       => $this->identifyNodeType($element)->value,
                'properties' => $this->getPropertiesFromNode($element),
                'identifier' => $key,
                'content'    => $element->attr['src'] ?? (string) $element->innertext ?? "",
                'global'     => isset($element->attr['global']),
            ]);
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

                // If no nodes found
                if(!isset($node->asArray()['items']) || count($node->asArray()['items']) === 0)
                {
                    // Break out the Loop from the tree
                    $loop_element_node = SimpleHtmlDom::string($_loop_element->innertext());

                    // Find out if any unidentified loop items
                    foreach($loop_element_node->find("node") AS $element)
                    {
                        $this->parseElement($element, $_loop_element->attr['name']);
                    }
                }
                else
                {
                    // Nodes found, parse them and extract parameters within
                    $i = 1;
                
                    foreach($node AS $key => $item) // $n == $node
                    {
                        // Break out the Loop from the tree (Each iteration replaces {{key}} + {{int}} uniquely)
                        $loop_element_node = SimpleHtmlDom::string($_loop_element->innertext());

                        foreach($loop_element_node->find("node") AS $element)
                        {
                            $this->parseElement($element, $_loop_element->attr['name'], $key);
                        }

                        $new_nodes[] = str_replace(["{{key}}", "{{int}}"], [$key, $i], (string) $loop_element_node);

                        $i++;
                    }
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
        private function parseElement(HtmlNode $_element, ?string $_parent = null, mixed $_key = null): void
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

                $tag = "innertext"; //($_element->hasChildNodes()) ? "content" : "innertext";
                $_element->$tag = ($type == "route") ? (string) $href : ((!empty((string) $href->prop("title")) ? (string) $href->prop("title") : (string) $href->prop("content")));

                if($_element->hasChildNodes())
                {
                    array_walk($_element->nodes, fn($e) => $_element->removeChild($e));
                }

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
            elseif($name)
            {
                if(!empty($_parent))
                {
                    $this->elements[self::ELEMENT_UNIDENTIFIED][$_parent] ??= array();
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