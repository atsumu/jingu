<?php

require_once __DIR__ . '/JinguParser.php';
require_once __DIR__ . '/JinguUtil.php';

$assert = new assert();

/* JinguParser */

function refcall($name, $t) {
    $m = new ReflectionMethod('JinguParser', $name);
    $m->setAccessible(true);
    try {
        return $m->invoke(new JinguParser(), $t, 0);
    } catch (JinguException $e) {
        return [ null, null, [ "message" => $e->getMessage() ] ];
    }
}

// end
$r = list($o, $v, $e) = refcall('end', '');
$assert->equal($r, $v === '');
$assert->equal($r, $e === null);

$r = list($o, $v, $e) = refcall('end', 'a');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// end_must
$r = list($o, $v, $e) = refcall('end_must', '');
$assert->equal($r, $v === '');
$assert->equal($r, $e === null);

$r = list($o, $v, $e) = refcall('end_must', 'a');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// newline_to_strip
$r = list($o, $v, $e) = refcall('newline_to_strip', "\n");
$assert->equal($r, $v === "<?\n?>");
$assert->equal($r, $e === null);

$r = list($o, $v, $e) = refcall('newline_to_strip', 'a');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// blank_char
$r = list($o, $v, $e) = refcall('blank_char', ' ');
$assert->equal($r, $v === ' ');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('blank_char', 'a');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// blank_string
$r = list($o, $v, $e) = refcall('blank_string', '  ');
$assert->equal($r, implode("", $v) === '  ');
$assert->equal($r, isset($e) === false);

// blank_line_unit_to_strip
$r = list($o, $v, $e) = refcall('blank_line_unit_to_strip', " \n");
$assert->equal($r, $v === "<?\n?>");
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('blank_line_unit_to_strip', ' ');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// blank_line_repeat_to_strip
$r = list($o, $v, $e) = refcall('blank_line_repeat_to_strip', '');
$assert->equal($r, $v === '');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('blank_line_repeat_to_strip', " \n\n");
$assert->equal($r, $v === "<?\n?><?\n?>");
$assert->equal($r, isset($e) === false);

// str_quoted
$r = list($o, $v, $e) = refcall('str_quoted', '"foo\""');
$assert->equal($r, $v === '"foo\""');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('str_quoted', 'foo');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// php_inner_repeat
$r = list($o, $v, $e) = refcall('php_inner_repeat', 'foo((1 + 1), "bar", [])');
$assert->equal($r, $v === 'foo((1 + 1), "bar", [])');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('php_inner_repeat', 'foo(');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// php_inattr_repeat
$r = list($o, $v, $e) = refcall('php_inattr_repeat', 'foo((1 + 1), "bar", [])+3');
$assert->equal($r, $v === 'foo((1 + 1), "bar", [])+3');
$assert->equal($r, isset($e) === false);

// php_inoperator_repeat
$r = list($o, $v, $e) = refcall('php_inoperator_repeat', 'foo((1 + 1), "bar", []) + 3');
$assert->equal($r, $v === 'foo((1 + 1), "bar", []) + 3');
$assert->equal($r, isset($e) === false);

// attr_key_name
$r = list($o, $v, $e) = refcall('attr_key_name', 'id');
$assert->equal($r, $v === 'id');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('attr_key_name', 'foo');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// attr_pair_str
$r = list($o, $v, $e) = refcall('attr_pair_str', 'id="contents"');
$assert->equal($r, $v === 'id="contents"');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('attr_pair_str', 'id="contents$foo{$bar}"');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

$r = list($o, $v, $e) = refcall('attr_pair_str', 'id="\""');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

$r = list($o, $v, $e) = refcall('attr_pair_str', 'id="&<>\'"');
$assert->equal($r, $v === 'id="&amp;&lt;&gt;&#039;"');
$assert->equal($r, isset($e) === false);

// attr_pair_php
$r = list($o, $v, $e) = refcall('attr_pair_php', 'id=foo("contents")');
$assert->equal($r, $v === 'id="<?= htmlspecialchars((foo("contents")), ENT_QUOTES, "UTF-8") ?>"');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('attr_pair_php', 'id="contents$foo{$bar}"');
$assert->equal($r, $v === 'id="<?= htmlspecialchars(("contents$foo{$bar}"), ENT_QUOTES, "UTF-8") ?>"');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('attr_pair_php', 'id=$foo["bar"]');
$assert->equal($r, $v === 'id="<?= htmlspecialchars(($foo["bar"]), ENT_QUOTES, "UTF-8") ?>"');
$assert->equal($r, isset($e) === false);

// attr_item
$r = list($o, $v, $e) = refcall('attr_item', 'id');
$assert->equal($r, $v === 'id');
$assert->equal($r, isset($e) === false);

// attr_unit
$r = list($o, $v, $e) = refcall('attr_unit', ' id="contents"');
$assert->equal($r, $v === 'id="contents"');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('attr_unit', 'id="contents"');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// attr_repeat
$r = list($o, $v, $e) = refcall('attr_repeat', ' id="contents" id="contents"');
$assert->equal($r, $v === ' id="contents" id="contents"');
$assert->equal($r, isset($e) === false);

// syntaxname_string
$r = list($o, $v, $e) = refcall('syntaxname_string', 'for');
$assert->equal($r, $v === 'for');
$assert->equal($r, isset($e) === false);

// syntax_end_token_to_strip_must
$r = list($o, $v, $e) = refcall('syntax_end_token_to_strip_must', "\n");
$assert->equal($r, $v === "<?\n?>");
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('syntax_end_token_to_strip_must', '');
$assert->equal($r, $v === '');
$assert->equal($r, isset($e) === false);

// htmltag_name_single
$r = list($o, $v, $e) = refcall('htmltag_name_single', 'img');
$assert->equal($r, $v === 'img');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('htmltag_name_single', 'div');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// htmltag_name_pair
$r = list($o, $v, $e) = refcall('htmltag_name_pair', 'div');
$assert->equal($r, $v === 'div');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('htmltag_name_pair', 'img');
$assert->equal($r, $v === null);
$assert->equal($r, isset($e) === true);

// syntax_child_repeat
$r = list($o, $v, $e) = refcall('syntax_child_repeat', ' foreach ($a as $b)
 foreach ($b as $c)
');
$assert->equal($r, $v === '<? foreach ($a as $b) { ?><?
?><? } ?><? foreach ($b as $c) { ?><?
?><? } ?>');
$assert->equal($r, isset($e) === false);

// htmltag_single
$r = list($o, $v, $e) = refcall('htmltag_single', 'img id="contents"');
$assert->equal($r, $v === '<img id="contents">');
$assert->equal($r, isset($e) === false);

// htmltag_pair
$r = list($o, $v, $e) = refcall('htmltag_pair', 'div id="contents"');
$assert->equal($r, $v === '<div id="contents"></div>');
$assert->equal($r, isset($e) === false);

// operator_textout_unit
$r = list($o, $v, $e) = refcall('operator_textout_unit', '| <$foo>');
$assert->equal($r, $v === '&lt;$foo&gt;');
$assert->equal($r, isset($e) === false);

// operator_phpout_unit
$r = list($o, $v, $e) = refcall('operator_phpout_unit', '= $foo');
$assert->equal($r, $v === '<?= htmlspecialchars(($foo), ENT_QUOTES, "UTF-8") ?>');
$assert->equal($r, isset($e) === false);

// operator_comment_unit
$r = list($o, $v, $e) = refcall('operator_comment_unit', '// foo');
$assert->equal($r, $v === '<? // foo ?>');
$assert->equal($r, isset($e) === false);

// operator_block_unit
$r = list($o, $v, $e) = refcall('operator_block_unit', 'block "foo" ($a, $b)

 img id="contents"
');
$assert->equal($r, $v === '<? }, "foo" => function ($a, $b) { ?><?
?><?
?><img id="contents"><?
?>');
$assert->equal($r, isset($e) === false);

// operator_braced_name
$r = list($o, $v, $e) = refcall('operator_braced_name', 'foreach');
$assert->equal($r, $v === 'foreach');
$assert->equal($r, isset($e) === false);

// operator_braced_unit
$r = list($o, $v, $e) = refcall('operator_braced_unit', 'foreach ($a as $b)

 foreach ($b as $c)
  img id="contents"
  img id="contents"
');
$assert->equal($r, $v === '<? foreach ($a as $b) { ?><?
?><?
?><? foreach ($b as $c) { ?><?
?><img id="contents"><?
?><img id="contents"><?
?><? } ?><? } ?>');
$assert->equal($r, isset($e) === false);

// operator_ifelse_unit
$r = list($o, $v, $e) = refcall('operator_ifelse_unit', 'if ($a == $b)
 img id="contents"
');
$assert->equal($r, $v === '<? if ($a == $b) { ?><?
?><img id="contents"><?
?><? } ?>');
$assert->equal($r, isset($e) === false);

$r = list($o, $v, $e) = refcall('operator_ifelse_unit', 'if ($a == $b)
 img id="a_is_b"
elseif ($a == $c)
 img id="a_is_c"
else
 img id="a_is_else"
');
$assert->equal($r, $v === '<? if ($a == $b) { ?><?
?><img id="a_is_b"><?
?><? } elseif ($a == $c) { ?><?
?><img id="a_is_c"><?
?><? } else { ?><?
?><img id="a_is_else"><?
?><? } ?>');
$assert->equal($r, isset($e) === false);

// parseString
$r = (new JinguParser())->parseString('foreach ($a as $b)
 // comment
 | <$text>

 foreach ($b as $c)

  if ($c == $d)

   img id="contents"

  elseif ($c == $e)

   = $foo

  else

   img id="contents"
block "foo" ($a, $b)
 | text
');
$v = $r["value"];
$e = $r["error"];
$assert->equal($r, $v === '<? return [ "" => function () { ?><? foreach ($a as $b) { ?><?
?><? // comment ?><?
?>&lt;$text&gt;<?
?><?
?><? foreach ($b as $c) { ?><?
?><?
?><? if ($c == $d) { ?><?
?><?
?><img id="contents"><?
?><?
?><? } elseif ($c == $e) { ?><?
?><?
?><?= htmlspecialchars(($foo), ENT_QUOTES, "UTF-8") ?><?
?><?
?><? } else { ?><?
?><?
?><img id="contents"><?
?><? } ?><? } ?><? } ?><? }, "foo" => function ($a, $b) { ?><?
?>text<?
?><? } ] ?>');
$assert->equal($r, isset($e) === false);

/* JinguUtil */

class TestJinguUtil extends JinguUtil {
    protected function templateDirectory() {
        return __DIR__;
    }
    protected function cacheDirectory() {
        return __DIR__;
    }
}

if (file_put_contents('test.jingu', 'div id="contents"', LOCK_EX) === false) {
    throw new Exception();
}

$jinguUtil = new TestJinguUtil(new JinguParser());
ob_start();
$jinguUtil->call('test', '', []);
$output = ob_get_clean();
$assert->equal($output, $output === '<div id="contents"></div>');

/* Total */
$assert->total();

/* Benchmark */
$st = microtime(1);
$r = (new JinguParser())->parseString(str_repeat(str_repeat('div id="contents"'."\n", 10)."\n", 10));
$et = microtime(1);
printf("time to parse 110 lines: %.6f\n", $et - $st);

//
exit($assert->isOK() ? 0 : 1);

class assert {
    private $okCount = 0;
    private $ngCount = 0;

    public function equal($a, $b) {
        $trace = debug_backtrace();
        if ($b) {
            $this->okCount += 1;
            printf("ok: L%s\n", $trace[0]['line']);
        } else {
            $this->ngCount += 1;
            printf("NG: L%s\n", $trace[0]['line']);
            printf("result: ");
            var_dump($a);
        }
    }

    public function isOK() {
        return $ngCount == 0;
    }

    public function total() {
        printf("count: ok=%s ng=%s\n", $this->okCount, $this->ngCount);
    }
}
