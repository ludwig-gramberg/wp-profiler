<?php
/**
 * Plugin Name: Profiler
 * Plugin URI: https://github.com/ludwig-gramberg/wp-profiler
 * Description: WP Profiler
 * Version: 0.1
 * Author: Ludwig Gramberg
 * Author URI: http://www.ludwig-gramberg.de/
 * Text Domain:
 * License: MIT
 */
class ProfilerNode {

    /**
     * @var string
     */
    public $name;

    /**
     * @var string
     */
    public $additional = '';

    /**
     * @var ProfilerNode
     */
    public $parent;

    /**
     * @var double
     */
    public $start;

    /**
     * @var double
     */
    public $stop = 0;

    /**
     * @var int
     */
    public $level = 0;

    /**
     * @var ProfilerNode[]
     */
    public $children = array();

    /**
     * @return float
     */
    public function getTimeMs() {
        return ($this->stop-$this->start)*1000;
    }

    /**
     * @return float
     */
    public function getMissingTimeMs() {
        if(empty($this->children)) {
            return 0;
        }
        $sumChildren = 0;
        foreach($this->children as $node) {
            $sumChildren += $node->getTimeMs();
        }
        $unprofiled = $this->getTimeMs() - $sumChildren;
        return $unprofiled < 1 ? 0 : $unprofiled;
    }

    public function __construct($name, $additional = null) {
        $this->start = microtime(true);
        $this->name = $name;
        $this->additional .= ' '.$additional;
    }

    public function stop($additional = null) {
        $this->additional .= ' '.$additional;
        $this->stop = microtime(true);
    }

    public function addChild(ProfilerNode $node) {
        $this->children[] = $node;
        $node->parent = $this;
        $node->level = $this->level+1;
    }

    /**
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }

    /**
     * @return ProfilerNode[]
     */
    public function getChildren() {
        return $this->children;
    }

    /**
     * @return ProfilerNode
     */
    public function getParent() {
        return $this->parent;
    }

    /**
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    public function getRootPath() {
        $path = array();
        $node = $this;
        while($node->parent !== null) {
            array_unshift($path, $this->name);
            $node = $node->parent;
        }
        return $path;
    }

    /**
     * @return string
     */
    public function out(&$out) {
        if($this->stop === null) {
            throw new Exception('node '.implode('/', $this->getRootPath()).' not closed/stopped');
        }
        $pre = str_repeat('    ', $this->level);

        $time = $this->getTimeMs();
        $unprofiled = $this->getMissingTimeMs();

        $out .= "\n".$pre.'<node time="'.number_format($time,2,'.','').'ms" name="'.str_replace('"', '', $this->getName()).'"'.($unprofiled > 0 ? ' unprofiled="'.number_format($unprofiled,2,'.','').'ms '.(number_format($unprofiled/($time/100),0)).'%"' : '');
        if(trim($this->additional) != '') {
            $out .= ' additional="'.str_replace('"','',trim($this->additional)).'"';
        }
        if(!empty($this->children)) {
            $out .= '>';
            foreach($this->children as $child) {
                $child->out($out);
            }
            $out .= "\n".$pre.'</node>';
        } else {
            $out .= '/>';
        }
    }
}


class Profiler {

    /* @var ProfilerNode */
    static protected $tree;

    /* @var ProfilerNode */
    static protected $node;

    /**
     * @return ProfilerNode
     */
    public static function getTree() {
        return self::$tree;
    }

    /**
     * @return ProfilerNode
     */
    public static function getNode() {
        return self::$node;
    }

    /**
     * @param $name
     */
    static public function start($name, $additional = null) {
        $node = new ProfilerNode($name, $additional);
        if(self::$tree === null) {
            self::$tree = $node;
            self::$node = self::$tree;
        } else {
            if(self::$node === null) {
                throw new Exception('root node was already closed');
            }
            self::$node->addChild($node);
            self::$node = $node;
        }
    }

    /**
     * @param $name
     */
    static public function stop($name, $additional = null) {
        if(self::$tree === null) {
            throw new Exception('closing node '.$name.' but tree not initiated');
        }
        if(self::$node === null) {
            throw new Exception('closing node '.$name.' but no node is open');
        }
        if(self::$node->getName() != $name) {
            throw new Exception('closing node '.$name.' but current node is '.self::$node->getName());
        }
        self::$node->stop($additional);
        self::$node = self::$node->getParent();
    }

    /**
     * @return string
     */
    static public function toXml() {
        $out = '<?xml version="1.0" charset="utf-8" ?>';
        self::$tree->out($out);
        return $out;
    }
}

function profiler_start($name, $additional = null) {
    try {
        Profiler::start($name, $additional);
    } catch(Exception $e) {
        trigger_error((string)$e, E_USER_WARNING);
    }
}
function profiler_stop($name, $additional = null) {
    try {
        Profiler::stop($name, $additional);
    } catch(Exception $e) {
        trigger_error((string)$e, E_USER_WARNING);
    }
}
function profiler_report() {
    try {
        return Profiler::toXml();
    } catch(Exception $e) {
        trigger_error((string)$e, E_USER_WARNING);
    }
}

if(array_key_exists('__profile', $_GET)) {

    add_action('profiler_start', 'profiler_start', 10, 2 );
    add_action('profiler_stop', 'profiler_stop', 10, 2 );

    // root
    function profiler_init() {
        Profiler::start('root');
        if(defined('PROFILER_REQUEST_START')) {
            Profiler::getTree()->start = PROFILER_REQUEST_START;
            Profiler::start('wp_init');
            Profiler::getNode()->start = PROFILER_REQUEST_START;
            Profiler::stop('wp_init');
        }
    }
    add_action('plugins_loaded', 'profiler_init', 1000);

    function profiler_shutdown() {
        Profiler::stop('root');
        $xml = Profiler::toXml();

        $dir = ABSPATH.'/wp-content/uploads/profiles/';
        if(!is_dir($dir)) {
            mkdir($dir);
        }
        if(is_dir($dir)) {
            $f=fopen($dir.'profile-'.time().'.xml', 'w');
            if($f) {
                fwrite($f, $xml);
                fclose($f);
            }
        }
    }
    add_action('shutdown', 'profiler_shutdown', 1000);
}