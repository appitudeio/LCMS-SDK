<?php
/**
 * Website: https://github.com/simplehtmldom/simplehtmldom
 *
 * Licensed under The MIT License
 * See the LICENSE file in the project root for more information.
 *
 * Authors:
 *   S.C. Chen
 *   John Schlick
 *   Rus Carroll
 *   logmanoriginal
 *
 * Contributors:
 *   Yousuke Kumakura
 *   Vadim Voituk
 *   Antcs
 *
 * Version 2.0-RC2
 */
namespace LCMS\Utils;

use \Exception;

/*define('HDOM_TYPE_ELEMENT',     HtmlNode::HDOM_TYPE_ELEMENT);
define('HDOM_TYPE_COMMENT',     HtmlNode::HDOM_TYPE_COMMENT);
define('HDOM_TYPE_TEXT',        HtmlNode::HDOM_TYPE_TEXT);
define('HDOM_TYPE_ROOT',        HtmlNode::HDOM_TYPE_ROOT);
define('HDOM_TYPE_UNKNOWN',     HtmlNode::HDOM_TYPE_UNKNOWN);
define('HDOM_QUOTE_DOUBLE',     HtmlNode::HDOM_QUOTE_DOUBLE);
define('HDOM_QUOTE_SINGLE',     HtmlNode::HDOM_QUOTE_SINGLE);
define('HDOM_QUOTE_NO',         HtmlNode::HDOM_QUOTE_NO);
define('HDOM_INFO_BEGIN',       HtmlNode::HDOM_INFO_BEGIN);
define('HDOM_INFO_END',         HtmlNode::HDOM_INFO_END);
define('HDOM_INFO_QUOTE',       HtmlNode::HDOM_INFO_QUOTE);
define('HDOM_INFO_SPACE',       HtmlNode::HDOM_INFO_SPACE);
define('HDOM_INFO_TEXT',        HtmlNode::HDOM_INFO_TEXT);
define('HDOM_INFO_INNER',       HtmlNode::HDOM_INFO_INNER);
define('HDOM_INFO_OUTER',       HtmlNode::HDOM_INFO_OUTER);
define('HDOM_INFO_ENDSPACE',    HtmlNode::HDOM_INFO_ENDSPACE);*/

define('HDOM_SMARTY_AS_TEXT', 1);

defined(__NAMESPACE__ . '\DEFAULT_TARGET_CHARSET') || define(__NAMESPACE__ . '\DEFAULT_TARGET_CHARSET', 'UTF-8');
defined(__NAMESPACE__ . '\DEFAULT_BR_TEXT') || define(__NAMESPACE__ . '\DEFAULT_BR_TEXT', "\r\n");
defined(__NAMESPACE__ . '\DEFAULT_SPAN_TEXT') || define(__NAMESPACE__ . '\DEFAULT_SPAN_TEXT', ' ');
defined(__NAMESPACE__ . '\MAX_FILE_SIZE') || define(__NAMESPACE__ . '\MAX_FILE_SIZE', 2621440);

class SimpleHtmlDom
{
    public static function url(    
        $url,
        $use_include_path = false,
        $context = null,
        $offset = 0,
        $maxLen = -1,
        $lowercase = true,
        $forceTagsClosed = true,
        $target_charset = DEFAULT_TARGET_CHARSET,
        $stripRN = true,
        $defaultBRText = DEFAULT_BR_TEXT,
        $defaultSpanText = DEFAULT_SPAN_TEXT)
    {
        if($maxLen <= 0) { $maxLen = MAX_FILE_SIZE; }

        $dom = new HtmlNode(
            null,
            $lowercase,
            $forceTagsClosed,
            $target_charset,
            $stripRN,
            $defaultBRText,
            $defaultSpanText
        );

        $contents = file_get_contents(
            $url,
            $use_include_path,
            $context,
            $offset,
            $maxLen + 1 // Load extra byte for limit check
        );

        if (empty($contents) || strlen($contents) > $maxLen) 
        {
            $dom->clear();
            return false;
        }

        return $dom->load($contents, $lowercase, $stripRN);
    }

    public static function string( 
        $str,
        $lowercase = true,
        $forceTagsClosed = true,
        $target_charset = DEFAULT_TARGET_CHARSET,
        $stripRN = true,
        $defaultBRText = DEFAULT_BR_TEXT,
        $defaultSpanText = DEFAULT_SPAN_TEXT)
    {
        $dom = new HtmlDocument(
            null,
            $lowercase,
            $forceTagsClosed,
            $target_charset,
            $stripRN,
            $defaultBRText,
            $defaultSpanText
        );

        if (empty($str) || strlen($str) > MAX_FILE_SIZE) 
        {
            $dom->clear();
            return false;
        }

        return $dom->load($str, $lowercase, $stripRN);
    }

    public static function createElement($name, $value = null)
    {
        return HtmlDocument::createElement($name, $value);
    }
}

class HtmlNode
{
    const HDOM_TYPE_ELEMENT = 1;
    const HDOM_TYPE_COMMENT = 2;
    const HDOM_TYPE_TEXT = 3;
    const HDOM_TYPE_ROOT = 5;
    const HDOM_TYPE_UNKNOWN = 6;
    const HDOM_TYPE_CDATA = 7;

    const HDOM_QUOTE_DOUBLE = 0;
    const HDOM_QUOTE_SINGLE = 1;
    const HDOM_QUOTE_NO = 3;

    const HDOM_INFO_BEGIN = 0;
    const HDOM_INFO_END = 1;
    const HDOM_INFO_QUOTE = 2;
    const HDOM_INFO_SPACE = 3;
    const HDOM_INFO_TEXT = 4;
    const HDOM_INFO_INNER = 5;
    const HDOM_INFO_OUTER = 6;
    const HDOM_INFO_ENDSPACE = 7;

    public $nodetype = self::HDOM_TYPE_TEXT;
    public $tag = 'text';
    public $attr = array();
    public $children = array();
    public $nodes = array();
    public $parent = null;
    public $_ = array();
    private $dom = null;

    function __call($func, $args)
    {
        // Allow users to call methods with lower_case syntax
        switch($func)
        {
            case 'children':
                $actual_function = 'childNodes'; break;
            case 'first_child':
                $actual_function = 'firstChild'; break;
            case 'has_child':
                $actual_function = 'hasChildNodes'; break;
            case 'last_child':
                $actual_function = 'lastChild'; break;
            case 'next_sibling':
                $actual_function = 'nextSibling'; break;
            case 'prev_sibling':
                $actual_function = 'previousSibling'; break;
            default:
                trigger_error(
                    'Call to undefined method ' . __CLASS__ . '::' . $func . '()',
                    E_USER_ERROR
                );
        }

        // phpcs:ignore Generic.Files.LineLength
        Debug::log(__CLASS__ . '->' . $func . '() has been deprecated and will be removed in the next major version of simplehtmldom. Use ' . __CLASS__ . '->' . $actual_function . '() instead.');

        return call_user_func_array(array($this, $actual_function), $args);
    }

    function __construct($dom)
    {
        if ($dom === null) return $this;

        $this->dom = $dom;
        $dom->nodes[] = $this;
    }

    function __debugInfo()
    {
        // Translate node type to human-readable form
        switch($this->nodetype)
        {
            case self::HDOM_TYPE_ELEMENT:
                $nodetype = "HDOM_TYPE_ELEMENT ($this->nodetype)";
                break;
            case self::HDOM_TYPE_COMMENT:
                $nodetype = "HDOM_TYPE_COMMENT ($this->nodetype)";
                break;
            case self::HDOM_TYPE_TEXT:
                $nodetype = "HDOM_TYPE_TEXT ($this->nodetype)";
                break;
            case self::HDOM_TYPE_ROOT:
                $nodetype = "HDOM_TYPE_ROOT ($this->nodetype)";
                break;
            case self::HDOM_TYPE_CDATA:
                $nodetype = "HDOM_TYPE_CDATA ($this->nodetype)";
                break;
            case self::HDOM_TYPE_UNKNOWN:
            default:
                $nodetype = "HDOM_TYPE_UNKNOWN ($this->nodetype)";
        }

        return array(
            'nodetype' => $nodetype,
            'tag' => $this->tag,
            'attributes' => empty($this->attr) ? 'none' : $this->attr,
            'nodes' => empty($this->nodes) ? 'none' : $this->nodes
        );
    }

    function __toString()
    {
        return $this->outertext();
    }

    function clear()
    {
        unset($this->dom); // Break link to origin
        unset($this->parent); // Break link to branch
    }

    /** @codeCoverageIgnore */
    function dump($show_attr = true, $depth = 0)
    {
        echo str_repeat("\t", $depth) . $this->tag;

        if ($show_attr && count($this->attr) > 0) {
            echo '(';
            foreach ($this->attr as $k => $v) {
                echo "[$k]=>\"$v\", ";
            }
            echo ')';
        }

        echo "\n";

        if ($this->nodes) {
            foreach ($this->nodes as $node) {
                $node->dump($show_attr, $depth + 1);
            }
        }
    }

    /** @codeCoverageIgnore */
    function dump_node($echo = true)
    {
        $string = $this->tag;

        if (count($this->attr) > 0) {
            $string .= '(';
            foreach ($this->attr as $k => $v) {
                $string .= "[$k]=>\"$v\", ";
            }
            $string .= ')';
        }

        if (count($this->_) > 0) {
            $string .= ' $_ (';
            foreach ($this->_ as $k => $v) {
                if (is_array($v)) {
                    $string .= "[$k]=>(";
                    foreach ($v as $k2 => $v2) {
                        $string .= "[$k2]=>\"$v2\", ";
                    }
                    $string .= ')';
                } else {
                    $string .= "[$k]=>\"$v\", ";
                }
            }
            $string .= ')';
        }

        if (isset($this->text)) {
            $string .= " text: ({$this->text})";
        }

        $string .= ' HDOM_INNER_INFO: ';

        if (isset($node->_[self::HDOM_INFO_INNER])) {
            $string .= "'" . $node->_[self::HDOM_INFO_INNER] . "'";
        } else {
            $string .= ' NULL ';
        }

        $string .= ' children: ' . count($this->children);
        $string .= ' nodes: ' . count($this->nodes);
        $string .= "\n";

        if ($echo) {
            echo $string;
            return;
        } else {
            return $string;
        }
    }

    function parent($parent = null)
    {
        // I am SURE that this doesn't work properly.
        // It fails to unset the current node from it's current parents nodes or
        // children list first.
        if ($parent !== null) {
            $this->parent = $parent;
            $this->parent->nodes[] = $this;
            $this->parent->children[] = $this;
        }

        return $this->parent;
    }

    function find_ancestor_tag($tag)
    {
        if ($this->parent === null) return null;

        $ancestor = $this->parent;

        while (!is_null($ancestor)) {
            if ($ancestor->tag === $tag) {
                break;
            }

            $ancestor = $ancestor->parent;
        }

        return $ancestor;
    }

    function innertext()
    {
        if (isset($this->_[self::HDOM_INFO_INNER])) {
            $ret = $this->_[self::HDOM_INFO_INNER];
        } elseif (isset($this->_[self::HDOM_INFO_TEXT])) {
            $ret = $this->_[self::HDOM_INFO_TEXT];
        } else {
            $ret = '';
        }

        foreach ($this->nodes as $n) {
            $ret .= $n->outertext();
        }

        return $this->convert_text($ret);
    }

    function outertext()
    {
        if ($this->tag === 'root') {
            return $this->innertext();
        }

        // todo: What is the use of this callback? Remove?
        if ($this->dom && $this->dom->callback !== null) {
            call_user_func_array($this->dom->callback, array($this));
        }

        if (isset($this->_[self::HDOM_INFO_OUTER])) {
            return $this->convert_text($this->_[self::HDOM_INFO_OUTER]);
        }

        if (isset($this->_[self::HDOM_INFO_TEXT])) {
            return $this->convert_text($this->_[self::HDOM_INFO_TEXT]);
        }

        $ret = '';

        if (isset($this->_[self::HDOM_INFO_BEGIN])) {
            $ret = $this->makeup();
        }

        if (isset($this->_[self::HDOM_INFO_INNER])) {
            // todo: <br> should either never have self::HDOM_INFO_INNER or always
            if ($this->tag !== 'br') {
                $ret .= $this->_[self::HDOM_INFO_INNER];
            }
        }

        if ($this->nodes) {
            foreach ($this->nodes as $n) {
                $ret .= $n->outertext();
            }
        }

        if (isset($this->_[self::HDOM_INFO_END]) && $this->_[self::HDOM_INFO_END] != 0) {
            $ret .= '</' . $this->tag . '>';
        }

        return $this->convert_text($ret);
    }

    /**
     * Returns true if the provided element is a block level element
     * @link https://www.w3resource.com/html/HTML-block-level-and-inline-elements.php
     */
    protected function is_block_element($node)
    {
        // todo: When we have the utility class this should be moved there
        return in_array(strtolower($node->tag), array(
            'p',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ol', 'ul',
            'pre',
            'address',
            'blockquote',
            'dl',
            'div',
            'fieldset',
            'form',
            'hr',
            'noscript',
            'table'
        ));
    }

    /**
     * Returns true if the provided element is an inline level element
     * @link https://www.w3resource.com/html/HTML-block-level-and-inline-elements.php
     */
    protected function is_inline_element($node)
    {
        // todo: When we have the utility class this should be moved there
        return in_array(strtolower($node->tag), array(
            'b', 'big', 'i', 'small', 'tt',
            'abbr', 'acronym', 'cite', 'code', 'dfn', 'em', 'kbd', 'strong', 'samp', 'var',
            'a', 'bdo', 'br', 'img', 'map', 'object', 'q', 'script', 'span', 'sub', 'sup',
            'button', 'input', 'label', 'select', 'textarea'
        ));
    }

    function text($trim = true)
    {
        $ret = '';

        if (strtolower($this->tag) === 'script') {
            $ret = '';
        } elseif (strtolower($this->tag) === 'style') {
            $ret = '';
        } elseif ($this->nodetype === self::HDOM_TYPE_COMMENT) {
            $ret = '';
        } elseif ($this->nodetype === self::HDOM_TYPE_CDATA) {
            $ret = $this->_[self::HDOM_INFO_INNER];
        } elseif ($this->nodetype === self::HDOM_TYPE_UNKNOWN) {
            $ret = '';
        } elseif (isset($this->_[self::HDOM_INFO_INNER])) {
            $ret = $this->_[self::HDOM_INFO_INNER];
        } elseif ($this->nodetype === self::HDOM_TYPE_TEXT) {
            $ret = $this->_[self::HDOM_INFO_TEXT];
        }

        if (is_null($this->nodes)) {
            return '';
        }

        foreach ($this->nodes as $n) {
            if ($this->is_block_element($n)) {

                $block = ltrim($this->convert_text($n->text(false)));

                if (empty($block))
                    continue;

                $ret = rtrim($ret) . "\n\n" . $block;

            } elseif ($this->is_inline_element($n)) {
                // todo: <br> introduces code smell because no space but \n
                if (strtolower($n->tag) === 'br') {
                    $ret .= $this->dom->default_br_text ?: DEFAULT_BR_TEXT;
                } else {
                    $inline = ltrim($this->convert_text($n->text(false)));

                    if (empty($inline))
                        continue;

                    $ret = $ret . $this->convert_text($n->text(false));
                }
            } else {
                $ret .= $this->convert_text($n->text(false));
            }
        }

        // Reduce whitespace at start/end to a single (or none) space
        $ret = preg_replace('/[ \t\n\r\0\x0B\xC2\xA0]+$/u', $trim ? '' : ' ', $ret);
        $ret = preg_replace('/^[ \t\n\r\0\x0B\xC2\xA0]+/u', $trim ? '' : ' ', $ret);

        return $ret;
    }

    function xmltext()
    {
        $ret = $this->innertext();
        $ret = str_ireplace('<![CDATA[', '', $ret);
        $ret = str_replace(']]>', '', $ret);
        return $ret;
    }

    function makeup()
    {
        // text, comment, unknown
        if (isset($this->_[self::HDOM_INFO_TEXT])) {
            return $this->_[self::HDOM_INFO_TEXT];
        }

        $ret = '<' . $this->tag;

        foreach ($this->attr as $key => $val) {

            // skip removed attribute
            if ($val === null || $val === false) { continue; }

            if (isset($this->_[self::HDOM_INFO_SPACE][$key])) {
                $ret .= $this->_[self::HDOM_INFO_SPACE][$key][0];
            } else {
                $ret .= ' ';
            }

            //no value attr: nowrap, checked selected...
            if ($val === true) {
                $ret .= $key;
            } else {
                if (isset($this->_[self::HDOM_INFO_QUOTE][$key])) {
                    $quote_type = $this->_[self::HDOM_INFO_QUOTE][$key];
                } else {
                    $quote_type = self::HDOM_QUOTE_DOUBLE;
                }

                switch ($quote_type)
                {
                    case self::HDOM_QUOTE_SINGLE:
                        $quote = '\'';
                        $val = htmlentities($val, ENT_QUOTES, $this->dom->target_charset);
                        break;
                    case self::HDOM_QUOTE_NO:
                        $quote = '';
                        break;
                    case self::HDOM_QUOTE_DOUBLE:
                    default:
                        $quote = '"';
                        $val = htmlentities($val, ENT_COMPAT, $this->dom->target_charset ?? "UTF-8");
                }

                $ret .= $key
                . (isset($this->_[self::HDOM_INFO_SPACE][$key]) ? $this->_[self::HDOM_INFO_SPACE][$key][1] : '')
                . '='
                . (isset($this->_[self::HDOM_INFO_SPACE][$key]) ? $this->_[self::HDOM_INFO_SPACE][$key][2] : '')
                . $quote
                . $val
                . $quote;
            }
        }

        if(isset($this->_[self::HDOM_INFO_ENDSPACE])) {
            $ret .= $this->_[self::HDOM_INFO_ENDSPACE];
        }

        return $ret . '>';
    }

    function find($selector, $idx = null, $lowercase = false)
    {
        $selectors = $this->parse_selector($selector);
        if (($count = count($selectors)) === 0) { return array(); }
        $found_keys = array();

        // find each selector
        for ($c = 0; $c < $count; ++$c) {
            // The change on the below line was documented on the sourceforge
            // code tracker id 2788009
            // used to be: if (($levle=count($selectors[0]))===0) return array();
            if (($levle = count($selectors[$c])) === 0) {
                Debug::log_once('Empty selector (' . $selector . ') matches nothing.');
                return array();
            }

            if (!isset($this->_[self::HDOM_INFO_BEGIN])) {
                Debug::log_once('Invalid operation. The current node has no start tag.');
                return array();
            }

            $head = array($this->_[self::HDOM_INFO_BEGIN] => 1);
            $cmd = ' '; // Combinator

            // handle descendant selectors, no recursive!
            for ($l = 0; $l < $levle; ++$l) {
                $ret = array();

                foreach ($head as $k => $v) {
                    $n = ($k === -1) ? $this->dom->root : $this->dom->nodes[$k];
                    //PaperG - Pass this optional parameter on to the seek function.
                    $n->seek($selectors[$c][$l], $ret, $cmd, $lowercase);
                }

                $head = $ret;
                $cmd = $selectors[$c][$l][6]; // Next Combinator
            }

            foreach ($head as $k => $v) {
                if (!isset($found_keys[$k])) {
                    $found_keys[$k] = 1;
                }
            }
        }

        // sort keys
        ksort($found_keys);

        $found = array();
        foreach ($found_keys as $k => $v) {
            $found[] = $this->dom->nodes[$k];
        }

        // return nth-element or array
        if (is_null($idx)) { return $found; }
        elseif ($idx < 0) { $idx = count($found) + $idx; }
        return (isset($found[$idx])) ? $found[$idx] : null;
    }

    function expect($selector, $idx = null, $lowercase = false)
    {
        return $this->find($selector, $idx, $lowercase) ?: null;
    }

    protected function seek($selector, &$ret, $parent_cmd, $lowercase = false)
    {
        list($ps_selector, $tag, $ps_element, $id, $class, $attributes, $cmb) = $selector;
        $nodes = array();

        if ($parent_cmd === ' ') { // Descendant Combinator
            // Find parent closing tag if the current element doesn't have a closing
            // tag (i.e. void element)
            $end = (!empty($this->_[self::HDOM_INFO_END])) ? $this->_[self::HDOM_INFO_END] : 0;
            if ($end == 0 && $this->parent) {
                $parent = $this->parent;
                while ($parent !== null && !isset($parent->_[self::HDOM_INFO_END])) {
                    $end -= 1;
                    $parent = $parent->parent;
                }
                $end += $parent->_[self::HDOM_INFO_END];
            }

            if ($end === 0) {
                $end = count($this->dom->nodes);
            }

            // Get list of target nodes
            $nodes_start = $this->_[self::HDOM_INFO_BEGIN] + 1;

            // remove() makes $this->dom->nodes non-contiguous; use what is left.
            $nodes = array_intersect_key(
                $this->dom->nodes,
                array_flip(range($nodes_start, $end))
            );
        } elseif ($parent_cmd === '>') { // Child Combinator
            $nodes = $this->children;
        } elseif ($parent_cmd === '+'
            && $this->parent
            && in_array($this, $this->parent->children)) { // Next-Sibling Combinator
                $index = array_search($this, $this->parent->children, true) + 1;
                if ($index < count($this->parent->children))
                    $nodes[] = $this->parent->children[$index];
        } elseif ($parent_cmd === '~'
            && $this->parent
            && in_array($this, $this->parent->children)) { // Subsequent Sibling Combinator
                $index = array_search($this, $this->parent->children, true);
                $nodes = array_slice($this->parent->children, $index);
        }

        // Go throgh each element starting at this element until the end tag
        // Note: If this element is a void tag, any previous void element is
        // skipped.
        foreach($nodes as $node) {
            $pass = true;

            // Skip root nodes
            if(!$node->parent) {
                unset($node);
                continue;
            }

            // Handle 'text' selector
            if($pass && $tag === 'text') {

                if($node->tag === 'text') {
                    $ret[array_search($node, $this->dom->nodes, true)] = 1;
                }

                if(isset($node->_[self::HDOM_INFO_INNER])) {
                    $ret[$node->_[self::HDOM_INFO_BEGIN]] = 1;
                }

                unset($node);
                continue;

            }

            // Handle 'cdata' selector
            if($pass && $tag === 'cdata') {

                if($node->tag === 'cdata') {
                    $ret[$node->_[self::HDOM_INFO_BEGIN]] = 1;
                }

                unset($node);
                continue;

            }

            // Handle 'comment'
            if($pass && $tag === 'comment' && $node->tag === 'comment') {
                $ret[$node->_[self::HDOM_INFO_BEGIN]] = 1;
                unset($node);
                continue;
            }

            // Skip if node isn't a child node (i.e. text nodes)
            if($pass && !in_array($node, $node->parent->children, true)) {
                unset($node);
                continue;
            }

            // Skip if tag doesn't match
            if ($pass && $tag !== '' && $tag !== $node->tag && $tag !== '*') {
                $pass = false;
            }

            // Skip if ID doesn't exist
            if ($pass && $id !== '' && !isset($node->attr['id'])) {
                $pass = false;
            }

            // Check if ID matches
            if ($pass && $id !== '' && isset($node->attr['id'])) {
                // Note: Only consider the first ID (as browsers do)
                $node_id = explode(' ', trim($node->attr['id']))[0];

                if($id !== $node_id) { $pass = false; }
            }

            // Check if all class(es) exist
            if ($pass && $class !== '' && is_array($class) && !empty($class)) {
                if (isset($node->attr['class'])) {
                    // Apply the same rules for the pattern and attribute value
                    // Attribute values must not contain control characters other than space
                    // https://www.w3.org/TR/html/dom.html#text-content
                    // https://www.w3.org/TR/html/syntax.html#attribute-values
                    // https://www.w3.org/TR/xml/#AVNormalize
                    $node_classes = preg_replace("/[\r\n\t\s]+/u", ' ', $node->attr['class']);
                    $node_classes = trim($node_classes);
                    $node_classes = explode(' ', $node_classes);

                    if ($lowercase) {
                        $node_classes = array_map('strtolower', $node_classes);
                    }

                    foreach($class as $c) {
                        if(!in_array($c, $node_classes)) {
                            $pass = false;
                            break;
                        }
                    }
                } else {
                    $pass = false;
                }
            }

            // Check attributes
            if ($pass
                && $attributes !== ''
                && is_array($attributes)
                && !empty($attributes)) {
                    foreach($attributes as $a) {
                        list (
                            $att_name,
                            $att_expr,
                            $att_val,
                            $att_inv,
                            $att_case_sensitivity
                        ) = $a;

                        // Handle indexing attributes (i.e. "[2]")
                        /**
                         * Note: This is not supported by the CSS Standard but adds
                         * the ability to select items compatible to XPath (i.e.
                         * the 3rd element within it's parent).
                         *
                         * Note: This doesn't conflict with the CSS Standard which
                         * doesn't work on numeric attributes anyway.
                         */
                        if (is_numeric($att_name)
                            && $att_expr === ''
                            && $att_val === '') {
                                $count = 0;

                                // Find index of current element in parent
                                foreach ($node->parent->children as $c) {
                                    if ($c->tag === $node->tag) ++$count;
                                    if ($c === $node) break;
                                }

                                // If this is the correct node, continue with next
                                // attribute
                                if ($count === (int)$att_name) continue;
                        }

                        // Check attribute availability
                        if ($att_inv) { // Attribute should NOT be set
                            if (isset($node->attr[$att_name])) {
                                $pass = false;
                                break;
                            }
                        } else { // Attribute should be set
                            // todo: "plaintext" is not a valid CSS selector!
                            if ($att_name !== 'plaintext'
                                && !isset($node->attr[$att_name])) {
                                    $pass = false;
                                    break;
                            }
                        }

                        // Continue with next attribute if expression isn't defined
                        if ($att_expr === '') continue;

                        // If they have told us that this is a "plaintext"
                        // search then we want the plaintext of the node - right?
                        // todo "plaintext" is not a valid CSS selector!
                        if ($att_name === 'plaintext') {
                            $nodeKeyValue = $node->text();
                        } else {
                            $nodeKeyValue = $node->attr[$att_name];
                        }

                        // If lowercase is set, do a case insensitive test of
                        // the value of the selector.
                        if ($lowercase) {
                            $check = $this->match(
                                $att_expr,
                                strtolower($att_val),
                                strtolower($nodeKeyValue),
                                $att_case_sensitivity
                            );
                        } else {
                            $check = $this->match(
                                $att_expr,
                                $att_val,
                                $nodeKeyValue,
                                $att_case_sensitivity
                            );
                        }

                        $check = $ps_element === 'not' ? !$check : $check;

                        if (!$check) {
                            $pass = false;
                            break;
                        }
                    }
            }

            // Found a match. Add to list and clear node
            $pass = $ps_selector === 'not' ? !$pass : $pass;
            if ($pass) $ret[$node->_[self::HDOM_INFO_BEGIN]] = 1;
            unset($node);
        }
    }

    protected function match($exp, $pattern, $value, $case_sensitivity)
    {
        if ($case_sensitivity === 'i') {
            $pattern = strtolower($pattern);
            $value = strtolower($value);
        }

        // Apply the same rules for the pattern and attribute value
        // Attribute values must not contain control characters other than space
        // https://www.w3.org/TR/html/dom.html#text-content
        // https://www.w3.org/TR/html/syntax.html#attribute-values
        // https://www.w3.org/TR/xml/#AVNormalize
        $pattern = preg_replace("/[\r\n\t\s]+/u", ' ', $pattern);
        $pattern = trim($pattern);

        $value = preg_replace("/[\r\n\t\s]+/u", ' ', $value);
        $value = trim($value);

        switch ($exp) {
            case '=':
                return ($value === $pattern);
            case '!=':
                return ($value !== $pattern);
            case '^=':
                return preg_match('/^' . preg_quote($pattern, '/') . '/', $value);
            case '$=':
                return preg_match('/' . preg_quote($pattern, '/') . '$/', $value);
            case '*=':
                return preg_match('/' . preg_quote($pattern, '/') . '/', $value);
            case '|=':
                /**
                 * [att|=val]
                 *
                 * Represents an element with the att attribute, its value
                 * either being exactly "val" or beginning with "val"
                 * immediately followed by "-" (U+002D).
                 */
                return strpos($value, $pattern) === 0;
            case '~=':
                /**
                 * [att~=val]
                 *
                 * Represents an element with the att attribute whose value is a
                 * whitespace-separated list of words, one of which is exactly
                 * "val". If "val" contains whitespace, it will never represent
                 * anything (since the words are separated by spaces). Also if
                 * "val" is the empty string, it will never represent anything.
                 */
                return in_array($pattern, explode(' ', trim($value)), true);
        }

        Debug::log('Unhandled attribute selector: ' . $exp . '!');
        return false;
    }

    protected function parse_selector($selector_string)
    {
        /**
         * Pattern of CSS selectors, modified from mootools (https://mootools.net/)
         *
         * Paperg: Add the colon to the attribute, so that it properly finds
         * <tag attr:ibute="something" > like google does.
         *
         * Note: if you try to look at this attribute, you MUST use getAttribute
         * since $dom->x:y will fail the php syntax check.
         *
         * Notice the \[ starting the attribute? and the @? following? This
         * implies that an attribute can begin with an @ sign that is not
         * captured. This implies that an html attribute specifier may start
         * with an @ sign that is NOT captured by the expression. Farther study
         * is required to determine of this should be documented or removed.
         *
         * Matches selectors in this order:
         *
         * [0] - full match
         *
         * [1] - pseudo selector
         *     (?:\:(\w+)\()?
         *     Matches the pseudo selector (optional)
         *
         * [2] - tag name
         *     ([\w:\*-]*)
         *     Matches the tag name consisting of zero or more words, colons,
         *     asterisks and hyphens.
         *
         * [3] - pseudo selector
         *     (?:\:(\w+)\()?
         *     Matches the pseudo selector (optional)
         *
         * [4] - id name
         *     (?:\#([\w-]+))
         *     Optionally matches a id name, consisting of an "#" followed by
         *     the id name (one or more words and hyphens).
         *
         * [5] - class names (including dots)
         *     (?:\.([\w\.-]+))?
         *     Optionally matches a list of classs, consisting of an "."
         *     followed by the class name (one or more words and hyphens)
         *     where multiple classes can be chained (i.e. ".foo.bar.baz")
         *
         * [6] - attributes
         *     ((?:\[@?(?:!?[\w:-]+)(?:(?:[!*^$|~]?=)[\"']?(?:.*?)[\"']?)?(?:\s*?(?:[iIsS])?)?\])+)?
         *     Optionally matches the attributes list
         *
         * [7] - separator
         *     ([\/, >+~]+)
         *     Matches the selector list separator
         */
        // phpcs:ignore Generic.Files.LineLength
        $pattern = "/(?:\:(\w+)\()?([\w:\*-]*)(?:\:(\w+)\()?(?:\#([\w-]+))?(?:|\.([\w\.-]+))?((?:\[@?(?:!?[\w:-]+)(?:(?:[!*^$|~]?=)[\"']?(?:.*?)[\"']?)?(?:\s*?(?:[iIsS])?)?\])+)?(?:\))?(?:\))?([\/, >+~]+)/is";

        preg_match_all(
            $pattern,
            trim($selector_string) . ' ', // Add final ' ' as pseudo separator
            $matches,
            PREG_SET_ORDER
        );

        $selectors = array();
        $result = array();

        foreach ($matches as $m) {
            $m[0] = trim($m[0]);

            // Skip NoOps
            if ($m[0] === '' || $m[0] === '/' || $m[0] === '//') { continue; }

            array_shift($m);

            // Convert to lowercase
            if ($this->dom->lowercase) {
                $m[1] = strtolower($m[1]);
            }

            // Extract classes
            if ($m[4] !== '') { $m[4] = explode('.', $m[4]); }

            /* Extract attributes (pattern based on the pattern above!)
             * [0] - full match
             * [1] - attribute name
             * [2] - attribute expression
             * [3] - attribute value
             * [4] - case sensitivity
             *
             * Note: Attributes can be negated with a "!" prefix to their name
             */
            if($m[5] !== '') {
                preg_match_all(
                    "/\[@?(!?[\w:-]+)(?:([!*^$|~]?=)[\"']?(.*?)[\"']?)?(?:\s+?([iIsS])?)?\]/is",
                    trim($m[5]),
                    $attributes,
                    PREG_SET_ORDER
                );

                // Replace element by array
                $m[5] = array();

                foreach($attributes as $att) {
                    // Skip empty matches
                    if(trim($att[0]) === '') { continue; }

                    $inverted = (isset($att[1][0]) && $att[1][0] === '!');
                    $m[5][] = array(
                        $inverted ? substr($att[1], 1) : $att[1], // Name
                        (isset($att[2])) ? $att[2] : '', // Expression
                        (isset($att[3])) ? $att[3] : '', // Value
                        $inverted, // Inverted Flag
                        (isset($att[4])) ? strtolower($att[4]) : '', // Case-Sensitivity
                    );
                }
            }

            // Sanitize Separator
            if ($m[6] !== '' && trim($m[6]) === '') { // Descendant Separator
                $m[6] = ' ';
            } else { // Other Separator
                $m[6] = trim($m[6]);
            }

            // Clear Separator if it's a Selector List
            if ($is_list = ($m[6] === ',')) { $m[6] = ''; }

            $result[] = $m;

            if ($is_list) { // Selector List
                $selectors[] = $result;
                $result = array();
            }
        }

        if (count($result) > 0) { $selectors[] = $result; }
        return $selectors;
    }

    function __get($name)
    {
        if (isset($this->attr[$name])) {
            return $this->convert_text($this->attr[$name]);
        }

        switch ($name) {
            case 'outertext': return $this->outertext();
            case 'innertext': return $this->innertext();
            case 'plaintext': return $this->text();
            case 'xmltext': return $this->xmltext();
        }

        return false;
    }

    function __set($name, $value)
    {
        switch ($name) {
            case 'outertext':
                $this->_[self::HDOM_INFO_OUTER] = $value;
                break;
            case 'innertext':
                if (isset($this->_[self::HDOM_INFO_TEXT])) {
                    $this->_[self::HDOM_INFO_TEXT] = '';
                }
                $this->_[self::HDOM_INFO_INNER] = $value;
                break;
            default: $this->attr[$name] = $value;
        }
    }

    function __isset($name)
    {
        switch ($name) {
            case 'outertext': return true;
            case 'innertext': return true;
            case 'plaintext': return true;
        }

        return isset($this->attr[$name]);
    }

    function __unset($name)
    {
        if (isset($this->attr[$name])) { unset($this->attr[$name]); }
    }

    function convert_text($text)
    {
        $converted_text = $text;

        $sourceCharset = '';
        $targetCharset = '';

        if ($this->dom) {
            $sourceCharset = strtoupper($this->dom->_charset);
            $targetCharset = strtoupper($this->dom->_target_charset);
        }

        if (!empty($sourceCharset) && !empty($targetCharset)) {
            if (strtoupper($sourceCharset) === strtoupper($targetCharset)) {
                $converted_text = $text;
            } elseif ((strtoupper($targetCharset) === 'UTF-8') && (self::is_utf8($text))) {
                Debug::log_once('The source charset was incorrectly detected as ' . $sourceCharset . ' but should have been UTF-8');
                $converted_text = $text;
            } else {
                $converted_text = iconv($sourceCharset, $targetCharset, $text);
            }
        }

        // Lets make sure that we don't have that silly BOM issue with any of the utf-8 text we output.
        if ($targetCharset === 'UTF-8') {
            if (substr($converted_text, 0, 3) === "\xef\xbb\xbf") {
                $converted_text = substr($converted_text, 3);
            }

            if (substr($converted_text, -3) === "\xef\xbb\xbf") {
                $converted_text = substr($converted_text, 0, -3);
            }
        }

        return $converted_text;
    }

    static function is_utf8($str)
    {
        $c = 0; $b = 0;
        $bits = 0;
        $len = strlen($str);
        for($i = 0; $i < $len; $i++) {
            $c = ord($str[$i]);
            if($c > 128) {
                if(($c >= 254)) { return false; }
                elseif($c >= 252) { $bits = 6; }
                elseif($c >= 248) { $bits = 5; }
                elseif($c >= 240) { $bits = 4; }
                elseif($c >= 224) { $bits = 3; }
                elseif($c >= 192) { $bits = 2; }
                else { return false; }
                if(($i + $bits) > $len) { return false; }
                while($bits > 1) {
                    $i++;
                    $b = ord($str[$i]);
                    if($b < 128 || $b > 191) { return false; }
                    $bits--;
                }
            }
        }
        return true;
    }

    function get_display_size()
    {
        $width = -1;
        $height = -1;

        if ($this->tag !== 'img') {
            return false;
        }

        // See if there is aheight or width attribute in the tag itself.
        if (isset($this->attr['width'])) {
            $width = $this->attr['width'];
        }

        if (isset($this->attr['height'])) {
            $height = $this->attr['height'];
        }

        // Now look for an inline style.
        if (isset($this->attr['style'])) {
            // Thanks to user gnarf from stackoverflow for this regular expression.
            $attributes = array();

            preg_match_all(
                '/([\w-]+)\s*:\s*([^;]+)\s*;?/',
                $this->attr['style'],
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2];
            }

            // If there is a width in the style attributes:
            if (isset($attributes['width']) && $width == -1) {
                // check that the last two characters are px (pixels)
                if (strtolower(substr($attributes['width'], -2)) === 'px') {
                    $proposed_width = substr($attributes['width'], 0, -2);
                    // Now make sure that it's an integer and not something stupid.
                    if (filter_var($proposed_width, FILTER_VALIDATE_INT)) {
                        $width = $proposed_width;
                    }
                }
            }

            // If there is a width in the style attributes:
            if (isset($attributes['height']) && $height == -1) {
                // check that the last two characters are px (pixels)
                if (strtolower(substr($attributes['height'], -2)) == 'px') {
                    $proposed_height = substr($attributes['height'], 0, -2);
                    // Now make sure that it's an integer and not something stupid.
                    if (filter_var($proposed_height, FILTER_VALIDATE_INT)) {
                        $height = $proposed_height;
                    }
                }
            }

        }

        // Future enhancement:
        // Look in the tag to see if there is a class or id specified that has
        // a height or width attribute to it.

        // Far future enhancement
        // Look at all the parent tags of this image to see if they specify a
        // class or id that has an img selector that specifies a height or width
        // Note that in this case, the class or id will have the img subselector
        // for it to apply to the image.

        // ridiculously far future development
        // If the class or id is specified in a SEPARATE css file thats not on
        // the page, go get it and do what we were just doing for the ones on
        // the page.

        $result = array(
            'height' => $height,
            'width' => $width
        );

        return $result;
    }

    function save($filepath = '')
    {
        $ret = $this->outertext();

        if ($filepath !== '') {
            file_put_contents($filepath, $ret, LOCK_EX);
        }

        return $ret;
    }

    function addClass($class)
    {
        if (is_string($class)) {
            $class = explode(' ', $class);
        }

        if (is_array($class)) {
            foreach($class as $c) {
                if (isset($this->class)) {
                    if ($this->hasClass($c)) {
                        continue;
                    } else {
                        $this->class .= ' ' . $c;
                    }
                } else {
                    $this->class = $c;
                }
            }
        }
    }

    function hasClass($class)
    {
        if (is_string($class)) {
            if (isset($this->class)) {
                return in_array($class, explode(' ', $this->class), true);
            }
        }

        return false;
    }

    function removeClass($class = null)
    {
        if (!isset($this->class)) {
            return;
        }

        if (is_null($class)) {
            $this->removeAttribute('class');
            return;
        }

        if (is_string($class)) {
            $class = explode(' ', $class);
        }

        if (is_array($class)) {
            $class = array_diff(explode(' ', $this->class), $class);
            if (empty($class)) {
                $this->removeAttribute('class');
            } else {
                $this->class = implode(' ', $class);
            }
        }
    }

    function getAllAttributes()
    {
        return $this->attr;
    }

    function getAttribute($name)
    {
        return $this->$name;
    }

    function setAttribute($name, $value)
    {
        $this->$name = $value;
    }

    function hasAttribute($name)
    {
        return isset($this->$name);
    }

    function removeAttribute($name)
    {
        unset($this->$name);
    }

    function remove()
    {
        if ($this->parent) {
            $this->parent->removeChild($this);
        }
    }

    function removeChild($node)
    {
        foreach($node->children as $child) {
            $node->removeChild($child);
        }

        // No need to re-index node->children because it is about to be removed!
        foreach($node->nodes as $entity) {
            $enidx = array_search($entity, $node->nodes, true);
            $edidx = array_search($entity, $node->dom->nodes, true);

            if ($enidx !== false) {
                unset($node->nodes[$enidx]);
            }

            if ($edidx !== false) {
                unset($node->dom->nodes[$edidx]);
            }
        }

        // No need to re-index node->nodes because it is about to be removed!
        $nidx = array_search($node, $this->nodes, true);
        $cidx = array_search($node, $this->children, true);
        $didx = array_search($node, $this->dom->nodes, true);

        if ($nidx !== false) {
            unset($this->nodes[$nidx]);
        }

        $this->nodes = array_values($this->nodes);

        if ($cidx !== false) {
            unset($this->children[$cidx]);
        }

        $this->children = array_values($this->children);

        if ($didx !== false) {
            unset($this->dom->nodes[$didx]);
        }

        // Do not re-index dom->nodes because nodes point to other nodes in the
        // array explicitly!

        $node->clear();
    }

    function replace($new_node)
    {
        if($this->parent) {
            $this->parent->replaceChild($new_node);
        }
    }

    function replaceChild($node)
    {
        pre($node);

        pre($node->root->nodes ?? $node->nodes);


        die();

        $node->nodes = $node->root->nodes ?? $node->nodes;

        //$node->clear();
    }

    function getElementById($id)
    {
        return $this->find("#$id", 0);
    }

    function getElementsById($id, $idx = null)
    {
        return $this->find("#$id", $idx);
    }

    function getElementByTagName($name)
    {
        return $this->find($name, 0);
    }

    function getElementsByTagName($name, $idx = null)
    {
        return $this->find($name, $idx);
    }

    function parentNode()
    {
        return $this->parent();
    }

    function childNodes($idx = -1)
    {
        if ($idx === -1) {
            return $this->children;
        }

        if (isset($this->children[$idx])) {
            return $this->children[$idx];
        }

        return null;
    }

    function firstChild()
    {
        if (count($this->children) > 0) {
            return $this->children[0];
        }
        return null;
    }

    function lastChild()
    {
        if (count($this->children) > 0) {
            return end($this->children);
        }
        return null;
    }

    function nextSibling()
    {
        if ($this->parent === null) {
            return null;
        }

        $idx = array_search($this, $this->parent->children, true);

        if ($idx !== false && isset($this->parent->children[$idx + 1])) {
            return $this->parent->children[$idx + 1];
        }

        return null;
    }

    function previousSibling()
    {
        if ($this->parent === null) {
            return null;
        }

        $idx = array_search($this, $this->parent->children, true);

        if ($idx !== false && $idx > 0) {
            return $this->parent->children[$idx - 1];
        }

        return null;
    }

    function hasChildNodes()
    {
        return !empty($this->children);
    }

    function nodeName()
    {
        return $this->tag;
    }

    function appendChild($node)
    {
        $node->parent = $this;
        $this->nodes[] = $node;
        $this->children[] = $node;

        if ($this->dom) { // Attach current node to DOM (recursively)
            $children = array($node);

            while($children) {
                $child = array_pop($children);
                $children = array_merge($children, $child->children);

                $this->dom->nodes[] = $child;
                $child->dom = $this->dom;
                $child->_[self::HDOM_INFO_BEGIN] = count($this->dom->nodes) - 1;
                $child->_[self::HDOM_INFO_END] = $child->_[self::HDOM_INFO_BEGIN];
            }

            $this->dom->root->_[self::HDOM_INFO_END] = count($this->dom->nodes) - 1;
        }

        return $this;
    }
}

class HtmlDocument
{
    public $root = null;
    public $nodes = array();
    public $callback = null;
    public $lowercase = false;
    public $original_size;
    public $size;

    protected $pos;
    protected $doc;
    protected $char;

    protected $cursor;
    protected $parent;
    protected $noise = array();
    protected $token_blank = " \t\r\n";
    protected $token_equal = ' =/>';
    protected $token_slash = " />\r\n\t";
    protected $token_attr = ' >';

    public $_charset = '';
    public $_target_charset = '';

    public $default_br_text = '';
    public $default_span_text = '';

    protected $self_closing_tags = array(
        'area' => 1,
        'base' => 1,
        'br' => 1,
        'col' => 1,
        'embed' => 1,
        'hr' => 1,
        'img' => 1,
        'input' => 1,
        'link' => 1,
        'meta' => 1,
        'param' => 1,
        'source' => 1,
        'track' => 1,
        'wbr' => 1
    );
    protected $block_tags = array(
        'body' => 1,
        'div' => 1,
        'form' => 1,
        'root' => 1,
        'span' => 1,
        'table' => 1
    );
    protected $optional_closing_tags = array(
        // Not optional, see
        // https://www.w3.org/TR/html/textlevel-semantics.html#the-b-element
        'b' => array('b' => 1),
        'dd' => array('dd' => 1, 'dt' => 1),
        // Not optional, see
        // https://www.w3.org/TR/html/grouping-content.html#the-dl-element
        'dl' => array('dd' => 1, 'dt' => 1),
        'dt' => array('dd' => 1, 'dt' => 1),
        'li' => array('li' => 1),
        'optgroup' => array('optgroup' => 1, 'option' => 1),
        'option' => array('optgroup' => 1, 'option' => 1),
        'p' => array('p' => 1),
        'rp' => array('rp' => 1, 'rt' => 1),
        'rt' => array('rp' => 1, 'rt' => 1),
        'td' => array('td' => 1, 'th' => 1),
        'th' => array('td' => 1, 'th' => 1),
        'tr' => array('td' => 1, 'th' => 1, 'tr' => 1),
    );

    function __call($func, $args)
    {
        // Allow users to call methods with lower_case syntax
        switch($func)
        {
            case 'load_file':
                $actual_function = 'loadFile'; break;
            case 'clear': return; /* no-op */
            default:
                trigger_error(
                    'Call to undefined method ' . __CLASS__ . '::' . $func . '()',
                    E_USER_ERROR
                );
        }

        // phpcs:ignore Generic.Files.LineLength
        Debug::log(__CLASS__ . '->' . $func . '() has been deprecated and will be removed in the next major version of simplehtmldom. Use ' . __CLASS__ . '->' . $actual_function . '() instead.');

        return call_user_func_array(array($this, $actual_function), $args);
    }

    function __construct(
        $str = null,
        $lowercase = true,
        $forceTagsClosed = true,
        $target_charset = DEFAULT_TARGET_CHARSET,
        $stripRN = true,
        $defaultBRText = DEFAULT_BR_TEXT,
        $defaultSpanText = DEFAULT_SPAN_TEXT,
        $options = 0)
    {
        if ($str) {
            if (preg_match('/^http:\/\//i', $str) || is_file($str)) {
                $this->load_file($str);
            } else {
                $this->load(
                    $str,
                    $lowercase,
                    $stripRN,
                    $defaultBRText,
                    $defaultSpanText,
                    $options
                );
            }
        } else {
            $this->prepare($str, $lowercase, $defaultBRText, $defaultSpanText);
        }
        // Forcing tags to be closed implies that we don't trust the html, but
        // it can lead to parsing errors if we SHOULD trust the html.
        if (!$forceTagsClosed) {
            $this->optional_closing_array = array();
        }

        $this->_target_charset = $target_charset;
    }

    function __debugInfo()
    {
        return array(
            'root' => $this->root,
            'noise' => empty($this->noise) ? 'none' : $this->noise,
            'charset' => $this->_charset,
            'target charset' => $this->_target_charset,
            'original size' => $this->original_size
        );
    }

    function __destruct()
    {
        if (isset($this->nodes)) {
            foreach ($this->nodes as $n) {
                $n->clear();
            }
        }
    }

    function load(
        $str,
        $lowercase = true,
        $stripRN = true,
        $defaultBRText = DEFAULT_BR_TEXT,
        $defaultSpanText = DEFAULT_SPAN_TEXT,
        $options = 0)
    {
        // prepare
        $this->prepare($str, $lowercase, $defaultBRText, $defaultSpanText);

        if ($stripRN) {
            // Temporarily remove any element that shouldn't loose whitespace
            $this->remove_noise("'<\s*script[^>]*>(.*?)<\s*/\s*script\s*>'is");
            $this->remove_noise("'<!\[CDATA\[(.*?)\]\]>'is");
            $this->remove_noise("'<!--(?!>|\->)(.*?)-->'is");
            $this->remove_noise("'<\s*style[^>]*>(.*?)<\s*/\s*style\s*>'is");
            $this->remove_noise("'<\s*(?:code)[^>]*>(.*?)<\s*/\s*(?:code)\s*>'is");

            // Remove whitespace and newlines between tags
            $this->doc = preg_replace('/\>([\t\s]*[\r\n]^[\t\s]*)\</m', '><', $this->doc);

            // Remove whitespace and newlines in text
            $this->doc = preg_replace('/([\t\s]*[\r\n]^[\t\s]*)/m', ' ', $this->doc);

            // Restore temporarily removed elements and calculate new size
            $this->doc = $this->restore_noise($this->doc);
            $this->size = strlen($this->doc);
        }

        $this->remove_noise("'(<\?)(.*?)(\?>)'s", true); // server-side script
        if (count($this->noise)) {
            // phpcs:ignore Generic.Files.LineLength
            Debug::log('Support for server-side scripts has been deprecated and will be removed in the next major version of simplehtmldom.');
        }

        if($options & HDOM_SMARTY_AS_TEXT) { // Strip Smarty scripts
            $this->remove_noise("'(\{\w)(.*?)(\})'s", true);
            // phpcs:ignore Generic.Files.LineLength
            Debug::log('Support for Smarty scripts has been deprecated and will be removed in the next major version of simplehtmldom.');
        }

        // parsing
        $this->parse($stripRN);
        // end
        $this->root->_[HtmlNode::HDOM_INFO_END] = $this->cursor;
        $this->parse_charset();
        $this->decode();
        unset($this->doc);

        // make load function chainable
        return $this;
    }

    function set_callback($function_name)
    {
        $this->callback = $function_name;
    }

    function remove_callback()
    {
        $this->callback = null;
    }

    function save($filepath = '')
    {
        $ret = $this->root->innertext();
        if ($filepath !== '') { file_put_contents($filepath, $ret, LOCK_EX); }
        return $ret;
    }

    function find($selector, $idx = null, $lowercase = false)
    {
        return $this->root->find($selector, $idx, $lowercase);
    }

    function expect($selector, $idx = null, $lowercase = false)
    {
        return $this->root->expect($selector, $idx, $lowercase);
    }

    /** @codeCoverageIgnore */
    function dump($show_attr = true)
    {
        $this->root->dump($show_attr);
    }

    protected function prepare(
        $str, $lowercase = true,
        $defaultBRText = DEFAULT_BR_TEXT,
        $defaultSpanText = DEFAULT_SPAN_TEXT)
    {
        $this->clear();

        $this->doc = trim($str ?? "");
        $this->size = strlen($this->doc);
        $this->original_size = $this->size; // original size of the html
        $this->pos = 0;
        $this->cursor = 1;
        $this->noise = array();
        $this->nodes = array();
        $this->lowercase = $lowercase;
        $this->default_br_text = $defaultBRText;
        $this->default_span_text = $defaultSpanText;
        $this->root = new HtmlNode($this);
        $this->root->tag = 'root';
        $this->root->_[HtmlNode::HDOM_INFO_BEGIN] = -1;
        $this->root->nodetype = HtmlNode::HDOM_TYPE_ROOT;
        $this->parent = $this->root;
        if ($this->size > 0) { $this->char = $this->doc[0]; }
    }

    protected function decode()
    {
        foreach($this->nodes as $node) {
            if (isset($node->_[HtmlNode::HDOM_INFO_TEXT])) {
                $node->_[HtmlNode::HDOM_INFO_TEXT] = html_entity_decode(
                    $this->restore_noise($node->_[HtmlNode::HDOM_INFO_TEXT]),
                    ENT_QUOTES | ENT_HTML5,
                    $this->_target_charset
                );
            }
            if (isset($node->_[HtmlNode::HDOM_INFO_INNER])) {
                $node->_[HtmlNode::HDOM_INFO_INNER] = html_entity_decode(
                    $this->restore_noise($node->_[HtmlNode::HDOM_INFO_INNER]),
                    ENT_QUOTES | ENT_HTML5,
                    $this->_target_charset
                );
            }
            if (isset($node->attr) && is_array($node->attr)) {
                foreach($node->attr as $a => $v) {
                    if ($v === true) continue;
                    $node->attr[$a] = html_entity_decode(
                        $v,
                        ENT_QUOTES | ENT_HTML5,
                        $this->_target_charset
                    );
                }
            }
        }
    }

    protected function parse($trim = false)
    {
        while (true) {

            if ($this->char !== '<') {
                $content = $this->copy_until_char('<');

                if ($content !== '') {

                    // Skip whitespace between tags? (</a> <b>)
                    if ($trim && trim($content) === '') {
                        continue;
                    }

                    $node = new HtmlNode($this);
                    ++$this->cursor;
                    $node->_[HtmlNode::HDOM_INFO_TEXT] = $content;
                    $this->link_nodes($node, false);
                }
            }

            if($this->read_tag($trim) === false) {
                break;
            }
        }
    }

    protected function parse_charset()
    {
        $charset = null;

        if (function_exists('get_last_retrieve_url_contents_content_type')) {
            $contentTypeHeader = get_last_retrieve_url_contents_content_type();
            $success = preg_match('/charset=(.+)/', $contentTypeHeader, $matches);
            if ($success) {
                $charset = $matches[1];
            }

            // phpcs:ignore Generic.Files.LineLength
            Debug::log('Determining charset using get_last_retrieve_url_contents_content_type() ' . ($success ? 'successful' : 'failed'));
        }

        if (empty($charset)) {
            // https://www.w3.org/TR/html/document-metadata.html#statedef-http-equiv-content-type
            $el = $this->root->find('meta[http-equiv=Content-Type]', 0, true);

            if (!empty($el)) {
                $fullvalue = $el->content;

                if (!empty($fullvalue)) {
                    $success = preg_match(
                        '/charset=(.+)/i',
                        $fullvalue,
                        $matches
                    );

                    if ($success) {
                        $charset = $matches[1];
                    }
                }
            }
        }

        if (empty($charset)) {
            // https://www.w3.org/TR/html/document-metadata.html#character-encoding-declaration
            if ($meta = $this->root->find('meta[charset]', 0)) {
                $charset = $meta->charset;
            }
        }

        if (empty($charset)) {
            // Try to guess the charset based on the content
            // Requires Multibyte String (mbstring) support (optional)
            if (function_exists('mb_detect_encoding')) {
                /**
                 * mb_detect_encoding() is not intended to distinguish between
                 * charsets, especially single-byte charsets. Its primary
                 * purpose is to detect which multibyte encoding is in use,
                 * i.e. UTF-8, UTF-16, shift-JIS, etc.
                 *
                 * -- https://bugs.php.net/bug.php?id=38138
                 *
                 * Adding both CP1251/ISO-8859-5 and CP1252/ISO-8859-1 will
                 * always result in CP1251/ISO-8859-5 and vice versa.
                 *
                 * Thus, only detect if it's either UTF-8 or CP1252/ISO-8859-1
                 * to stay compatible.
                 */
                $encoding = mb_detect_encoding(
                    $this->doc,
                    array( 'UTF-8', 'CP1252', 'ISO-8859-1' )
                );

                if ($encoding === 'CP1252' || $encoding === 'ISO-8859-1') {
                    // Due to a limitation of mb_detect_encoding
                    // 'CP1251'/'ISO-8859-5' will be detected as
                    // 'CP1252'/'ISO-8859-1'. This will cause iconv to fail, in
                    // which case we can simply assume it is the other charset.
                    if (!iconv('CP1252', 'UTF-8//IGNORE', $this->doc)) {
                        $encoding = 'CP1251';
                    }
                }

                if ($encoding !== false) {
                    $charset = $encoding;
                }
            }
        }

        if (empty($charset)) {
            Debug::log('Unable to determine charset from source document. Assuming UTF-8');
            $charset = 'UTF-8';
        }

        // Since CP1252 is a superset, if we get one of it's subsets, we want
        // it instead.
        if ((strtolower($charset) == 'iso-8859-1')
            || (strtolower($charset) == 'latin1')
            || (strtolower($charset) == 'latin-1')) {
            $charset = 'CP1252';
        }

        return $this->_charset = $charset;
    }

    protected function read_tag($trim)
    {
        if ($this->char !== '<') { // End Of File
            $this->root->_[HtmlNode::HDOM_INFO_END] = $this->cursor;

            // We might be in a nest of unclosed elements for which the end tags
            // can be omitted. Close them for faster seek operations.
            do {
                if (isset($this->optional_closing_tags[strtolower($this->parent->tag)])) {
                    $this->parent->_[HtmlNode::HDOM_INFO_END] = $this->cursor;
                }
            } while ($this->parent = $this->parent->parent);

            return false;
        }

        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

        if ($trim) { // "<   /html>"
            $this->skip($this->token_blank);
        }

        // End tag: https://dev.w3.org/html5/pf-summary/syntax.html#end-tags
        if ($this->char === '/') {
            $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

            $tag = $this->copy_until_char('>');
            $tag = $trim ? ltrim($tag, $this->token_blank) : $tag;

            // Skip attributes and whitespace in end tags
            if ($trim && ($pos = strpos($tag, ' ')) !== false) {
                // phpcs:ignore Generic.Files.LineLength
                Debug::log_once('Source document contains superfluous whitespace in end tags (</html   >).');
                $tag = substr($tag, 0, $pos);
            }

            if (strcasecmp($this->parent->tag, $tag)) { // Parent is not start tag
                $parent_lower = strtolower($this->parent->tag);
                $tag_lower = strtolower($tag);
                if (isset($this->optional_closing_tags[$parent_lower]) && isset($this->block_tags[$tag_lower])) {
                    $org_parent = $this->parent;

                    // Look for the start tag
                    while (($this->parent->parent) && strtolower($this->parent->tag) !== $tag_lower){
                        // Close any unclosed element with optional end tags
                        if (isset($this->optional_closing_tags[strtolower($this->parent->tag)]))
                            $this->parent->_[HtmlNode::HDOM_INFO_END] = $this->cursor;
                        $this->parent = $this->parent->parent;
                    }

                    // No start tag, close grandparent
                    if (strtolower($this->parent->tag) !== $tag_lower) {
                        $this->parent = $org_parent;

                        if ($this->parent->parent) {
                            $this->parent = $this->parent->parent;
                        }

                        $this->parent->_[HtmlNode::HDOM_INFO_END] = $this->cursor;
                        return $this->as_text_node($tag);
                    }
                } elseif (($this->parent->parent) && isset($this->block_tags[$tag_lower])) {
                    // grandparent exists + current is block tag
                    // Parent has no end tag
                    $this->parent->_[HtmlNode::HDOM_INFO_END] = 0;
                    $org_parent = $this->parent;

                    // Find start tag
                    while (($this->parent->parent) && strtolower($this->parent->tag) !== $tag_lower) {
                        $this->parent = $this->parent->parent;
                    }

                    // No start tag, close parent
                    if (strtolower($this->parent->tag) !== $tag_lower) {
                        $this->parent = $org_parent; // restore origonal parent
                        $this->parent->_[HtmlNode::HDOM_INFO_END] = $this->cursor;
                        return $this->as_text_node($tag);
                    }
                } elseif (($this->parent->parent) && strtolower($this->parent->parent->tag) === $tag_lower) {
                    // Grandparent exists and current tag closes it
                    $this->parent->_[HtmlNode::HDOM_INFO_END] = 0;
                    $this->parent = $this->parent->parent;
                } else { // Random tag, add as text node
                    return $this->as_text_node($tag);
                }
            }

            // Link with start tag
            $this->parent->_[HtmlNode::HDOM_INFO_END] = $this->cursor;

            if ($this->parent->parent) {
                $this->parent = $this->parent->parent;
            }

            $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        // Start tag: https://dev.w3.org/html5/pf-summary/syntax.html#start-tags
        $node = new HtmlNode($this);
        $node->_[HtmlNode::HDOM_INFO_BEGIN] = $this->cursor++;

        // Tag name
        $tag = $this->copy_until($this->token_slash);

        if (isset($tag[0]) && $tag[0] === '!') { // Doctype, CData, Comment
            if (isset($tag[2]) && $tag[1] === '-' && $tag[2] === '-') { // Comment ("<!--")

                /**
                 * Comments must have the following format:
                 *
                 * 1. The string "<!--"
                 *
                 * 2. Optionally, text, with the additional restriction that the
                 * text must not start with the string ">", nor start with the
                 * string "->", nor contain the strings "<!--", "-->", or "--!>",
                 * nor end with the string "<!-".
                 *
                 * 3. The string "-->"
                 *
                 * -- https://www.w3.org/TR/html53/syntax.html#comments
                 */

                // Go back until $tag only contains start of comment "!--".
                while (strlen($tag) > 3) {
                    $this->char = $this->doc[--$this->pos]; // previous
                    $tag = substr($tag, 0, strlen($tag) - 1);
                }

                $node->nodetype = HtmlNode::HDOM_TYPE_COMMENT;
                $node->tag = 'comment';

                $data = '';

                while(true) {
                    // Copy until first char of end tag
                    $data .= $this->copy_until_char('-');

                    // Look ahead in the document, maybe we are at the end
                    if (($this->pos + 3) > $this->size) { // End of document
                        Debug::log('Source document ended unexpectedly!');
                        break;
                    } elseif (substr($this->doc, $this->pos, 3) === '-->') { // end
                        $data .= $this->copy_until_char('>');
                        break;
                    }

                    $data .= $this->char;
                    $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                }

                if (substr($data, 0, 1) === '>') { // "<!-->"
                    Debug::log('Comment must not start with the string ">"!');
                    $this->pos -= strlen($data);
                    $this->char = $this->doc[$this->pos];
                    $data = '';
                }

                if (substr($data, 0, 2) === '->') { // "<!--->"
                    Debug::log('Comment must not start with the string "->"!');
                    $this->pos -= strlen($data);
                    $this->char = $this->doc[$this->pos];
                    $data = '';
                }

                if (strpos($data, '<!--') !== false) { // "<!--<!---->"
                    Debug::log('Comment must not contain the string "<!--"!');
                    // simplehtmldom can work with it anyway
                }

                if (strpos($data, '--!>') !== false) { // "<!----!>-->"
                    Debug::log('Comment must not contain the string "--!>"!');
                    // simplehtmldom can work with it anyway
                }

                if (substr($data, -3, 3) === '<!-') { // "<!--<!--->"
                    Debug::log('Comment must not end with "<!-"!');
                    // simplehtmldom can work with it anyway
                }

                $tag .= $data;
                $tag = $this->restore_noise($tag);

                // Comment starts after "!--" and ends before "--" (5 chars total)
                $node->_[HtmlNode::HDOM_INFO_INNER] = substr($tag, 3, strlen($tag) - 5);
            } elseif (substr($tag, 1, 7) === '[CDATA[') {

                // Go back until $tag only contains start of cdata "![CDATA[".
                while (strlen($tag) > 8) {
                    $this->char = $this->doc[--$this->pos]; // previous
                    $tag = substr($tag, 0, strlen($tag) - 1);
                }

                // CDATA can contain HTML stuff, need to find closing tags first
                $node->nodetype = HtmlNode::HDOM_TYPE_CDATA;
                $node->tag = 'cdata';

                $data = '';

                // There is a rare chance of empty CDATA: "<[CDATA[]]>"
                // In which case the current char is the first "[" of the end tag
                // But the CDATA could also just be a bracket: "<[CDATA[]]]>"
                while(true) {
                    // Copy until first char of end tag
                    $data .= $this->copy_until_char(']');

                    // Look ahead in the document, maybe we are at the end
                    if (($this->pos + 3) > $this->size) { // End of document
                        Debug::log('Source document ended unexpectedly!');
                        break;
                    } elseif (substr($this->doc, $this->pos, 3) === ']]>') { // end
                        $data .= $this->copy_until_char('>');
                        break;
                    }

                    $data .= $this->char;
                    $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                }

                $tag .= $data;
                $tag = $this->restore_noise($tag);

                // CDATA starts after "![CDATA[" and ends before "]]" (10 chars total)
                $node->_[HtmlNode::HDOM_INFO_INNER] = substr($tag, 8, strlen($tag) - 10);
            } else { // Unknown
                Debug::log('Source document contains unknown declaration: <' . $tag);
                $node->nodetype = HtmlNode::HDOM_TYPE_UNKNOWN;
                $node->tag = 'unknown';
            }

            $node->_[HtmlNode::HDOM_INFO_TEXT] = '<' . $tag . $this->copy_until_char('>');

            if ($this->char === '>') {
                $node->_[HtmlNode::HDOM_INFO_TEXT] .= '>';
            }

            $this->link_nodes($node, true);
            $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        if (!preg_match('/^\w[\w:-]*$/', $tag)) { // Invalid tag name
            $node->_[HtmlNode::HDOM_INFO_TEXT] = '<' . $tag . $this->copy_until('<>');

            if ($this->char === '>') { // End tag
                $node->_[HtmlNode::HDOM_INFO_TEXT] .= '>';
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
            }

            $this->link_nodes($node, false);
            Debug::log('Source document contains invalid tag name: ' . $node->_[HtmlNode::HDOM_INFO_TEXT]);
            return true;
        }

        // Valid tag name
        $node->nodetype = HtmlNode::HDOM_TYPE_ELEMENT;
        $tag_lower = strtolower($tag);
        $node->tag = ($this->lowercase) ? $tag_lower : $tag;

        if (isset($this->optional_closing_tags[$tag_lower])) { // Optional closing tag
            while (isset($this->optional_closing_tags[$tag_lower][strtolower($this->parent->tag)])) {
                // Previous element was the last element of ancestor
                $this->parent->_[HtmlNode::HDOM_INFO_END] = $node->_[HtmlNode::HDOM_INFO_BEGIN] - 1;
                $this->parent = $this->parent->parent;
            }
            $node->parent = $this->parent;
        }

        $guard = 0; // prevent infinity loop

        // [0] Space between tag and first attribute
        $space = array($this->copy_skip($this->token_blank), '', '');

        do { // Parse attributes
            $name = $this->copy_until($this->token_equal);

            if ($name === '' && $this->char !== null && $space[0] === '') {
                break;
            }

            if ($guard === $this->pos) { // Escape infinite loop
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                continue;
            }

            $guard = $this->pos;

            if ($this->pos >= $this->size - 1 && $this->char !== '>') { // End Of File
                Debug::log('Source document ended unexpectedly!');
                $node->nodetype = HtmlNode::HDOM_TYPE_TEXT;
                $node->_[HtmlNode::HDOM_INFO_END] = 0;
                $node->_[HtmlNode::HDOM_INFO_TEXT] = '<' . $tag . $space[0] . $name;
                $node->tag = 'text';
                $this->link_nodes($node, false);
                return true;
            }

            if ($name === '/' || $name === '') { // No more attributes
                break;
            }

            // [1] Whitespace after attribute name
            $space[1] = (strpos($this->token_blank, $this->char) === false) ? '' : $this->copy_skip($this->token_blank);

            $name = $this->restore_noise($name); // might be a noisy name

            if ($this->lowercase) {
                $name = strtolower($name);
            }

            if ($this->char === '=') { // Attribute with value
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                $this->parse_attr($node, $name, $space, $trim); // get attribute value
            } else { // Attribute without value
                $node->_[HtmlNode::HDOM_INFO_QUOTE][$name] = HtmlNode::HDOM_QUOTE_NO;
                $node->attr[$name] = true;
                if ($this->char !== '>') {
                    $this->char = $this->doc[--$this->pos];
                } // prev
            }

            // Space before attribute and around equal sign
            if (!$trim && $space !== array(' ', '', '')) {
                // phpcs:ignore Generic.Files.LineLength
                Debug::log_once('Source document contains superfluous whitespace in attributes (<e    attribute  =  "value">). Enable trimming or fix attribute spacing for best performance.');
                $node->_[HtmlNode::HDOM_INFO_SPACE][$name] = $space;
            }

            // prepare for next attribute
            $space = array(
                ((strpos($this->token_blank, $this->char) === false) ? '' : $this->copy_skip($this->token_blank)),
                '',
                ''
            );
        } while ($this->char !== '>' && $this->char !== '/');

        $this->link_nodes($node, true);

        // Space after last attribute before closing the tag
        if (!$trim && $space[0] !== '') {
            // phpcs:ignore Generic.Files.LineLength
            Debug::log_once('Source document contains superfluous whitespace before the closing braket (<e attribute="value"     >). Enable trimming or remove spaces before closing brackets for best performance.');
            $node->_[HtmlNode::HDOM_INFO_ENDSPACE] = $space[0];
        }

        $rest = ($this->char === '>') ? '' : $this->copy_until_char('>');
        $rest = ($trim) ? trim($rest) : $rest; // <html   /   >

        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

        if (trim($rest) === '/') { // Void element
            if ($rest !== '') {
                if (isset($node->_[HtmlNode::HDOM_INFO_ENDSPACE])) {
                    $node->_[HtmlNode::HDOM_INFO_ENDSPACE] .= $rest;
                } else {
                    $node->_[HtmlNode::HDOM_INFO_ENDSPACE] = $rest;
                }
            }
            $node->_[HtmlNode::HDOM_INFO_END] = 0;
        } elseif (!isset($this->self_closing_tags[strtolower($node->tag)])) {
            $innertext = $this->copy_until_char('<');
            if ($innertext !== '') {
                $node->_[HtmlNode::HDOM_INFO_INNER] = $innertext;
            }
            $this->parent = $node;
        }

        if ($node->tag === 'br') {
            $node->_[HtmlNode::HDOM_INFO_INNER] = $this->default_br_text;
        } elseif ($node->tag === 'script') {
            $data = '';

            // There is a rare chance of empty script: "<script></script>"
            // In which case the current char is the start of the end tag
            // But the script could also just contain tags: "<script><div></script>"
            while(true) {
                // Copy until first char of end tag
                $data .= $this->copy_until_char('<');

                // Look ahead in the document, maybe we are at the end
                if (($this->pos + 9) > $this->size) { // End of document
                    Debug::log('Source document ended unexpectedly!');
                    break;
                } elseif (substr($this->doc, $this->pos, 8) === '</script') { // end
                    $this->skip('>'); // don't include the end tag
                    break;
                }

                // Note: A script tag may contain any other tag except </script>
                // which needs to be escaped as <\/script>

                $data .= $this->char;
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
            }

            $node = new HtmlNode($this);
            ++$this->cursor;
            $node->_[HtmlNode::HDOM_INFO_TEXT] = $data;
            $this->link_nodes($node, false);
        }

        return true;
    }

    protected function parse_attr($node, $name, &$space, $trim)
    {
        $is_duplicate = isset($node->attr[$name]);

        if (!$is_duplicate) // Copy whitespace between "=" and value
            $space[2] = (strpos($this->token_blank, $this->char) === false) ? '' : $this->copy_skip($this->token_blank);

        switch ($this->char) {
            case '"':
                $quote_type = HtmlNode::HDOM_QUOTE_DOUBLE;
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                $value = $this->copy_until_char('"');
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                break;
            case '\'':
                // phpcs:ignore Generic.Files.LineLength
                Debug::log_once('Source document contains attribute values with single quotes (<e attribute=\'value\'>). Use double quotes for best performance.');
                $quote_type = HtmlNode::HDOM_QUOTE_SINGLE;
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                $value = $this->copy_until_char('\'');
                $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
                break;
            default:
                // phpcs:ignore Generic.Files.LineLength
                Debug::log_once('Source document contains attribute values without quotes (<e attribute=value>). Use double quotes for best performance');
                $quote_type = HtmlNode::HDOM_QUOTE_NO;
                $value = $this->copy_until($this->token_attr);
        }

        $value = $this->restore_noise($value);

        if ($trim) {
            // Attribute values must not contain control characters other than space
            // https://www.w3.org/TR/html/dom.html#text-content
            // https://www.w3.org/TR/html/syntax.html#attribute-values
            // https://www.w3.org/TR/xml/#AVNormalize
            $value = preg_replace("/[\r\n\t\s]+/u", ' ', $value);
            $value = trim($value ?? ""); 
        }

        if (!$is_duplicate) {
            if ($quote_type !== HtmlNode::HDOM_QUOTE_DOUBLE) {
                $node->_[HtmlNode::HDOM_INFO_QUOTE][$name] = $quote_type;
            }
            $node->attr[$name] = $value;
        }
    }

    protected function link_nodes(&$node, $is_child)
    {
        $node->parent = $this->parent;
        $this->parent->nodes[] = $node;
        if ($is_child) {
            $this->parent->children[] = $node;
        }
    }

    protected function as_text_node($tag)
    {
        $node = new HtmlNode($this);
        ++$this->cursor;
        $node->_[HtmlNode::HDOM_INFO_TEXT] = '</' . $tag . '>';
        $this->link_nodes($node, false);
        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        return true;
    }

    protected function skip($chars)
    {
        $this->pos += strspn($this->doc, $chars, $this->pos);
        $this->char = ($this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
    }

    protected function copy_skip($chars)
    {
        $pos = $this->pos;
        $len = strspn($this->doc, $chars, $pos);
        if ($len === 0) { return ''; }
        $this->pos += $len;
        $this->char = ($this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        return substr($this->doc, $pos, $len);
    }

    protected function copy_until($chars)
    {
        $pos = $this->pos;
        $len = strcspn($this->doc, $chars, $pos);
        $this->pos += $len;
        $this->char = ($this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        return substr($this->doc, $pos, $len);
    }

    protected function copy_until_char($char)
    {
        if ($this->char === null) { return ''; }

        if (($pos = strpos($this->doc, $char, $this->pos)) === false) {
            $ret = substr($this->doc, $this->pos, $this->size - $this->pos);
            $this->char = null;
            $this->pos = $this->size;
            return $ret;
        }

        if ($pos === $this->pos) { return ''; }

        $pos_old = $this->pos;
        $this->char = $this->doc[$pos];
        $this->pos = $pos;
        return substr($this->doc, $pos_old, $pos - $pos_old);
    }

    protected function remove_noise($pattern, $remove_tag = false)
    {
        $count = preg_match_all(
            $pattern,
            $this->doc,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        for ($i = $count - 1; $i > -1; --$i) {
            $key = '___noise___' . sprintf('% 5d', count($this->noise) + 1000);

            $idx = ($remove_tag) ? 0 : 1; // 0 = entire match, 1 = submatch
            $this->noise[$key] = $matches[$i][$idx][0];
            $this->doc = substr_replace($this->doc, $key, $matches[$i][$idx][1], strlen($matches[$i][$idx][0]));
        }

        // reset the length of content
        $this->size = strlen($this->doc);

        if ($this->size > 0) {
            $this->char = $this->doc[0];
        }
    }

    function restore_noise($text)
    {
        if (empty($this->noise)) return $text; // nothing to restore
        $pos = 0;
        while (($pos = strpos($text, '___noise___', $pos)) !== false) {
            // Sometimes there is a broken piece of markup, and we don't GET the
            // pos+11 etc... token which indicates a problem outside of us...

            // todo: "___noise___1000" (or any number with four or more digits)
            // in the DOM causes an infinite loop which could be utilized by
            // malicious software
            if (strlen($text) > $pos + 15) {
                $key = '___noise___'
                . $text[$pos + 11]
                . $text[$pos + 12]
                . $text[$pos + 13]
                . $text[$pos + 14]
                . $text[$pos + 15];

                if (isset($this->noise[$key])) {
                    $text = substr($text, 0, $pos)
                    . $this->noise[$key]
                    . substr($text, $pos + 16);

                    unset($this->noise[$key]);
                } else {
                    Debug::log_once('Noise restoration failed. DOM has been corrupted!');
                    // do this to prevent an infinite loop.
                    // FIXME: THis causes an infinite loop because the keyword ___NOISE___ is included in the key!
                    $text = substr($text, 0, $pos)
                    . 'UNDEFINED NOISE FOR KEY: '
                    . $key
                    . substr($text, $pos + 16);
                }
            } else {
                // There is no valid key being given back to us... We must get
                // rid of the ___noise___ or we will have a problem.
                Debug::log_once('Noise restoration failed. The provided key is incomplete: ' . $text);
                $text = substr($text, 0, $pos)
                . 'NO NUMERIC NOISE KEY'
                . substr($text, $pos + 11);
            }
        }
        return $text;
    }

    function search_noise($text)
    {
        foreach($this->noise as $noiseElement) {
            if (strpos($noiseElement, $text) !== false) {
                return $noiseElement;
            }
        }
    }

    function __toString()
    {
        return $this->root->innertext();
    }

    function __get($name)
    {
        switch ($name) {
            case 'outertext':
                return $this->root->innertext();
            case 'innertext':
                return $this->root->innertext();
            case 'plaintext':
                return $this->root->text();
            case 'charset':
                return $this->_charset;
            case 'target_charset':
                return $this->_target_charset;
        }
    }

    function childNodes($idx = -1)
    {
        return $this->root->childNodes($idx);
    }

    function firstChild()
    {
        return $this->root->firstChild();
    }

    function lastChild()
    {
        return $this->root->lastChild();
    }

    public static function createElement($name, $value = null)
    {
        $node = new HtmlNode(null);
        $node->nodetype = HtmlNode::HDOM_TYPE_ELEMENT;
        $node->_[HtmlNode::HDOM_INFO_BEGIN] = 1;
        $node->_[HtmlNode::HDOM_INFO_END] = 1;

        if ($value !== null) {
            $node->_[HtmlNode::HDOM_INFO_INNER] = $value;
        }

        $node->tag = $name;

        return $node;
    }

    function createTextNode($value)
    {
        $node = new HtmlNode($this);
        $node->nodetype = HtmlNode::HDOM_TYPE_TEXT;

        if ($value !== null) {
            $node->_[HtmlNode::HDOM_INFO_TEXT] = $value;
        }

        return $node;
    }

    function getElementById($id)
    {
        return $this->find("#$id", 0);
    }

    function getElementsById($id, $idx = null)
    {
        return $this->find("#$id", $idx);
    }

    function getElementByTagName($name)
    {
        return $this->find($name, 0);
    }

    function getElementsByTagName($name, $idx = null)
    {
        return $this->find($name, $idx);
    }

    function loadFile($file)
    {
        $args = func_get_args();

        if(($doc = call_user_func_array('file_get_contents', $args)) !== false) {
            $this->load($doc, true);
        } else {
            return false;
        }
    }
}

class Debug {

    private static $enabled = false;
    private static $debugHandler = null;
    private static $callerLock = array();

    /**
     * Checks whether debug mode is enabled.
     *
     * @return bool True if debug mode is enabled, false otherwise.
     */
    public static function isEnabled()
    {
        return self::$enabled;
    }

    /**
     * Enables debug mode
     */
    public static function enable()
    {
        self::$enabled = true;
        self::log('Debug mode has been enabled');
    }

    /**
     * Disables debug mode
     */
    public static function disable()
    {
        self::log('Debug mode has been disabled');
        self::$enabled = false;
    }

    /**
     * Sets the debug handler.
     *
     * `null`: error_log (default)
     */
    public static function setDebugHandler($function = null)
    {
        if ($function === self::$debugHandler) return;

        self::log('New debug handler registered');
        self::$debugHandler = $function;
    }

    /**
     * This is the actual log function. It allows to set a custom backtrace to
     * eliminate traces of this class.
     */
    private static function log_trace($message, $backtrace)
    {
        $idx = 0;
        $debugmessage = '';

        foreach($backtrace as $caller)
        {
            if (!isset($caller['file']) && !isset($caller['line'])) {
                break; // Unknown caller
            }

            $debugmessage .= ' [' . $caller['file'] . ':' . $caller['line'];

            if ($idx > 1) { // Do not include the call to Debug::log
                $debugmessage .= ' '
                . $caller['class']
                . $caller['type']
                . $caller['function']
                . '()';
            }

            $debugmessage .= ']';

            // Stop at the first caller that isn't part of simplehtmldom
            if (!isset($caller['class']) || strpos($caller['class'], 'simplehtmldom\\') !== 0) {
                break;
            }
        }

        $output = '[DEBUG] ' . trim($debugmessage) . ' "' . $message . '"';

        if (is_null(self::$debugHandler)) {
            error_log($output);
        } else {
            call_user_func_array(self::$debugHandler, array($output));
        }
    }

    /**
     * Adds a debug message to error_log if debug mode is enabled. Does nothing
     * if debug mode is disabled.
     *
     * @param string $text The message to add to error_log
     */
    public static function log($message)
    {
        if (!self::isEnabled()) return;

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        self::log_trace($message, $backtrace);
    }

    /**
     * Adds a debug message to error_log if debug mode is enabled. Does nothing
     * if debug mode is disabled. Each message is logged only once.
     *
     * @param string $text The message to add to error_log
     */
    public static function log_once($message)
    {
        if (!self::isEnabled()) return;

        // Keep track of caller (file & line)
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        if (in_array($backtrace[0], self::$callerLock, true)) return;

        self::$callerLock[] = $backtrace[0];
        self::log_trace($message, $backtrace);
    }
}