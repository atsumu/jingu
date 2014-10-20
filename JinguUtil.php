<?php

abstract class JinguUtil {
    private $blockMap = [];
    private $parser;

    abstract protected function templateDirectory();
    abstract protected function cacheDirectory();

    public function __construct($parser) {
        $this->parser = $parser;
    }

    private function compileFile($cachePathPrefix, $templatePath) {
        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new Exception();
        }
        $cachePath = $cachePathPrefix . '.' . md5($content);
        if (file_exists($cachePath)) {
            return $cachePath;
        }
        $compiledCode = $this->parser->parseString($content);
        if (file_put_contents($cachePath, $compiledCode, LOCK_EX) === false) {
            throw new Exception();
        }
        return $cachePath;
    }

    public function call($templateName, $callBlockName, $args) {
        if (!isset($blockMap[$templateName])) {
            $templatePath = $this->templateDirectory() . '/' . $templateName . '.jingu';
            $cachePathPrefix = $this->cacheDirectory() . '/' . $templateName . '.jingu.php';
            $cachePath = $this->compileFile($cachePathPrefix, $templatePath);
            $blockList = include $cachePath;
            foreach ($blockList as $name => $block) {
                $blockList[$name] = Closure::bind($block, $this);
            }
            $blockMap[$templateName] = $blockList;
        }
        call_user_func_array($blockMap[$templateName][$callBlockName], $args);
    }
}
