<?php

error_reporting(E_ALL);

set_error_handler(function ($n, $s, $f, $l) {
    if (error_reporting()) {
        throw new ErrorException($s, 0, $n, $f, $l);
    }
});

set_exception_handler(function($e) {
    fprintf(STDERR, "date: %s\n", date("c"));
    fprintf(STDERR, "message: %s\n", $e->getMessage());
    fprintf(STDERR, "code: %s\n", $e->getCode());
    fprintf(STDERR, "file: %s\n", $e->getFile());
    fprintf(STDERR, "line: %s\n", $e->getLine());
    fprintf(STDERR, "trace:\n%s\n", $e->getTraceAsString());
});

date_default_timezone_set('Asia/Tokyo');
ini_set('xdebug.max_nesting_level', 512);

function debug($x) {
    $trace = debug_backtrace();
    fprintf(STDERR, "%s", basename($trace[0]["file"]).": ");
    fprintf(STDERR, "%s", "L".$trace[0]["line"].": ");
    fprintf(STDERR, "%s", var_export($x, 1));
    fprintf(STDERR, "%s", "\n");
}

final class JinguGen {
    private $debugLevel = 0;
    private $optimizeCodeSize = false;

    private $uGensymCounter = 0;
    private $parsers = [];
    private $fixedNames = [];
    private $currentName = null;
    private $outputByteMap = [];
    private $outputCountMap = [];

    private function __construct() {
    }

    /* Utilities */

    private function uGensym($identity = '') {
        return '$'.$identity.'_GS'.($this->uGensymCounter += 1);
    }

    private function uDump($value) {
        if (is_string($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $value = preg_replace('/(^|[^\\\\])\\\\u00/', '\1\\x', $value);
            $value = preg_replace('/\$/', '\\x24', $value);
            return $value;
        } else if (is_array($value)) {
            $s = 'array ('."\n";
            foreach ($value as $key => $item) {
                $s .= $this->uDump($key).' => '.$this->uDump($item).",\n";
            }
            $s .= ')';
            return $s;
        } else {
            return var_export($value, 1);
        }
    }

    private function uFlatIL($oil) {
        $nil = [];
        foreach ($oil as $tok_or_il) {
            if (is_array($tok_or_il)) {
                foreach ($tok_or_il as $tok) {
                    $nil[] = $tok;
                }
            } else {
                $nil[] = $tok_or_il;
            }
        }
        return $nil;
    }

    private function uMergeIL($oil, $okil, $ngil) {
        $nil = [];
        foreach ($oil as $otok) {
            if ($otok === '#OK') {
                if (is_array($okil)) {
                    foreach ($okil as $oktok) {
                        $nil[] = $oktok;
                    }
                } else {
                    $nil[] = $okil;
                }
            } else if ($otok === '#NG') {
                if (is_array($ngil)) {
                    foreach ($ngil as $ngtok) {
                        $nil[] = $ngtok;
                    }
                } else {
                    $nil[] = $ngil;
                }
            } else {
                $nil[] = $otok;
            }
        }
        return $nil;
    }

    private function uReturn() {
        return 'return [ $o, $v, $e ];';
    }

    private function uNewE($message, $args, $offset, $children) {
        return '$v = null; $e = [ "message" => '.$this->uDump($message).', "args" => '.$this->uDump($args).', "offset" => '.$offset.', "children" => '.$children.' ]';
    }

    private function uNewR($o, $Sv) {
        return '$o = '.$o.'; $v = '.$Sv.'; $e = null';
    }

    private function uRef($name, $okil, $ngil) {
        $noinlineList = [];
        if ($this->optimizeCodeSize) {
            $noinlineList = [
                'blank_line_repeat_to_strip',
                'php_wrapping',
                'php_inattr_repeat',
                'php_inoperator_repeat',
                'attr_repeat',
                'syntaxname_string',
                'syntax_end_token_to_strip_must',
                'syntax_child_repeat',
                'htmltag_single',
                'htmltag_pair',
                'operator_phpout_unit',
                'operator_braced_unit',
                'operator_ifelse_unit',
                'syntax_select',
                'syntax_top_unit',
                'syntax_top_repeat',
            ];
        }
        if (isset($this->fixedNames[$name]) && !in_array($name, $noinlineList, true)) {
            $cil = $this->parsers[$name];
            $bytes = 0;
            foreach ($cil as $ctok) {
                $bytes += strlen($ctok);
            }
            if (!isset($this->outputByteMap[$name])) {
                $this->outputByteMap[$name] = 0;
                $this->outputCountMap[$name] = 0;
            }
            $this->outputByteMap[$name] += $bytes;
            $this->outputCountMap[$name] += 1;
        } else {
            $cil = $this->uFlatIL([ '
                list($o, $v, $e) = $this->'.$name.'($t, $o);
                if (isset($e)) {
                    ', '#NG', '
                } else {
                    ', '#OK', '
                }
            ' ]);
        }
        return $this->uMergeIL($cil, $okil, $ngil);
    }

    /* Parser Generators */

    private function name($name) {
        if (isset($this->currentName)) {
            $this->fixedNames[$this->currentName] = true;
        }
        if ($this->debugLevel >= 1) {
            $dbgil = $this->uFlatIL([ '
                fprintf(STDERR, "%s\n    %s\n", '.$this->uDump($name).', json_encode(substr($t, $o, 100)));
            ' ]);
        } else {
            $dbgil = '';
        }
        $this->currentName = $name;
        $this->parsers[$this->currentName] = $this->uFlatIL([ '
            ', $dbgil, '
            ', '#OK', '
        ' ]);
        return $this;
    }

    private function ref($name) {
        $cil = $this->uRef($name, '#OK', '#NG');
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function end() {
        $cil = $this->uFlatIL([ '
            /* end */
            if ($o === strlen($t)) {
                '.$this->uNewR('$o', '""').';
                ', '#OK', '
            } else {
                '.$this->uNewE("end", [], '$o', 'null').';
                ', '#NG', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function s($string) {
        $len = strlen($string);
        $cil = $this->uFlatIL([ '
            /* s */
            if (isset($t[$o]) && substr_compare($t, '.$this->uDump($string).', $o, '.$this->uDump($len).') === 0) {
                '.$this->uNewR('$o + '.$len, $this->uDump($string)).';
                ', '#OK', '
            } else {
                '.$this->uNewE("s", [ $string ], '$o', 'null').';
                ', '#NG', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function byteTable($bytes) {
        $table = [];
        for ($i = 0; isset($bytes[$i]); $i++) {
            $table[$bytes[$i]] = true;
        }
        $GStable = $this->uGensym('table');
        $cil = $this->uFlatIL([ '
            /* byteTable */
            if (!isset('.$GStable.')) {
                '.$GStable.' = '.$this->uDump($table).';
            }
            if (isset($t[$o]) && isset('.$GStable.'[$t[$o]])) {
                '.$this->uNewR('$o + 1', '$t[$o]').';
                ', '#OK', '
            } else {
                '.$this->uNewE("byteTable", [ $bytes ], '$o', 'null').';
                ', '#NG', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function byteNotTable($bytes) {
        $table = [];
        for ($i = 0; isset($bytes[$i]); $i++) {
            $table[$bytes[$i]] = true;
        }
        $GStable = $this->uGensym('table');
        $cil = $this->uFlatIL([ '
            /* byteNotTable */
            if (!isset('.$GStable.')) {
                '.$GStable.' = '.$this->uDump($table).';
            }
            if (isset($t[$o]) && !isset('.$GStable.'[$t[$o]])) {
                '.$this->uNewR('$o + 1', '$t[$o - 1]').';
                ', '#OK', '
            } else {
                '.$this->uNewE("byteNotTable", [ $bytes ], '$o', 'null').';
                ', '#NG', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function select($names) {
        $GSes = $this->uGensym('es');
        $cil = $this->uFlatIL([ '
            /* select */
            '.$GSes.' = [];
            do {
                ', array_reduce($names, function ($il, $name) use ($GSes) {
                    $ril = $this->uRef($name, 'break;', $GSes.'[] = $e;');
                    foreach ($ril as $rtok) {
                        $il[] = $rtok;
                    }
                    return $il;
                }, []), '
            } while (0);
            if (isset($e)) {
                '.$this->uNewE("select", [ $names ], '$o', $GSes).';
                ', '#NG', '
            } else {
                ', '#OK', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function seq($names) {
        $GSvs = $this->uGensym('vs');
        $GSo = $this->uGensym('o');
        $cil = $this->uFlatIL([ '
            /* seq */
            do {
                '.$GSvs.' = [];
                '.$GSo.' = $o;
                ', array_reduce($names, function ($il, $name) use ($GSvs, $GSo, $names) {
                    $okil = $this->uFlatIL([ '
                        '.$GSvs.'[] = $v;
                    ' ]);
                    $ngil = $this->uFlatIL([ '
                        '.$this->uNewE("seq", [ $names ], '$o', '$e').';
                        break;
                    ' ]);
                    $ril = $this->uRef($name, $okil, $ngil);
                    foreach ($ril as $rtok) {
                        $il[] = $rtok;
                    }
                    return $il;
                }, []), '
            } while (0);
            if (isset($e)) {
                $o = '.$GSo.';
                ', '#NG', '
            } else {
                '.$this->uNewR('$o', $GSvs).';
                ', '#OK', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function repeat($name, $min = null, $max = null) {
        $GSo = $this->uGensym('o');
        $GSi = $this->uGensym('i');
        $GSvs = $this->uGensym('vs');
        $cil = $this->uFlatIL([ '
            /* repeat */
            '.$GSo.' = $o;
            '.$GSvs.' = [];
            for ('.$GSi.' = 0; '.($max === null ? '' : $GSi.' < '.$this->uDump($max).' && ').'isset($t[$o]); '.$GSi.'++) {
                ', $this->uRef($name, '', 'break;'), '
                '.$GSvs.'[] = $v;
            }
            if ('.$GSi.' < '.$this->uDump($min).') {
                '.$this->uNewE("repeat", [ $name, $min, $max ], '$o', 'isset($e) ? $e : null').';
                $o = '.$GSo.';
                ', '#NG', '
            } else {
                '.$this->uNewR('$o', $GSvs).';
                ', '#OK', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function any() {
        $cil = $this->uFlatIL([ '
            /* any */
            if (isset($t[$o])) {
                '.$this->uNewR('$o + 1', '$t[$o]').';
                ', '#OK', '
            } else {
                '.$this->uNewE("any", [], '$o', 'null').';
                ', '#NG', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function must() {
        $ngil = $this->uFlatIL([ '
            /* must */
            throw new JinguParseException($t, $e);
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], '#OK', $ngil);
        return $this;
    }

    private function phpString() {
        $GSo = $this->uGensym('o');
        $cil = $this->uFlatIL([ '
            /* phpString */
            '.$GSo.' = $o;
            do {
                if (!isset($t[$o])) {
                    '.$this->uNewE("phpString", [], '$o', 'null').';
                    break;
                }
                if ($t[$o] === '.$this->uDump("\'").') {
                    $close = '.$this->uDump("\'").';
                } else if ($t[$o] === '.$this->uDump("\"").') {
                    $close = '.$this->uDump("\"").';
                } else {
                    '.$this->uNewE("phpString", [], '$o', 'null').';
                    break;
                }
                $o += 1;
                while (true) {
                    if (!isset($t[$o])) {
                        '.$this->uNewE("phpString", [], '$o', 'null').';
                        break 2;
                    }
                    if ($t[$o] === $close) {
                        $o += 1;
                        break;
                    }
                    if ($t[$o] === '.$this->uDump("\\").') {
                        $o += 1;
                    }
                    $o += 1;
                }
                '.$this->uNewR('$o', 'substr($t, '.$GSo.', $o - '.$GSo.')').';
            } while (0);
            if (isset($e)) {
                $o = '.$GSo.';
                ', '#NG', '
            } else {
                ', '#OK', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function attrString() {
        $GSo = $this->uGensym('o');
        $cil = $this->uFlatIL([ '
            /* attrString */
            do {
                '.$GSo.' = $o;
                if (!isset($t[$o])) {
                    '.$this->uNewE("attrString", [], '$o', 'null').';
                    break;
                }
                if ($t[$o] === '.$this->uDump("\'").') {
                    $close = '.$this->uDump("\'").';
                } else if ($t[$o] === '.$this->uDump("\"").') {
                    $close = '.$this->uDump("\"").';
                } else {
                    '.$this->uNewE("attrString", [], '$o', 'null').';
                    break;
                }
                $o += 1;
                while (true) {
                    if (!isset($t[$o])) {
                        '.$this->uNewE("attrString", [], '$o', 'null').';
                        break 2;
                    }
                    if ($t[$o] === $close) {
                        $o += 1;
                        break;
                    }
                    if ($t[$o] === '.$this->uDump("\\").') {
                        '.$this->uNewE("can not use '\\' in attrString", [], '$o', 'null').';
                        break 2;
                    } else if ($t[$o] === '.$this->uDump('$').') {
                        '.$this->uNewE("can not use '\$' in attrString", [], '$o', 'null').';
                        break 2;
                    }
                    $o += 1;
                }
                '.$this->uNewR('$o', 'substr($t, '.$GSo.', $o - '.$GSo.')').';
            } while (0);
            if (isset($e)) {
                $o = '.$GSo.';
                ', '#NG', '
            } else {
                ', '#OK', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function indentNest($n, $name) {
        $GSi = $this->uGensym('i');
        $cil = $this->uFlatIL([ '
            /* indent */
            $this->indentLevel += 1;
            for ('.$GSi.' = 0; '.$GSi.' < $this->indentLevel && isset($t[$o + '.$GSi.']); '.$GSi.'++) {
                if ($t[$o] !== " ") {
                    break;
                }
            }
            if ('.$GSi.' < $this->indentLevel) {
                '.$this->uNewE("indentNest", [ $name, '$this->indentLevel' ], '$o', 'isset($e) ? $e : null').';
                $this->indentLevel -= 1;
                ', '#NG', '
            } else {
                '.$this->uNewR('$o + '.$GSi, 'null').';
                ', $this->uRef($name, [ '#OK', '$this->indentLevel -= 1;' ], [ '$this->indentLevel -= 1;', '#NG' ]), '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function indentCurrent() {
        $GSi = $this->uGensym('i');
        $cil = $this->uFlatIL([ '
            /* indent */
            for ('.$GSi.' = 0; '.$GSi.' < $this->indentLevel && isset($t[$o + '.$GSi.']); '.$GSi.'++) {
                if ($t[$o] !== " ") {
                    break;
                }
            }
            if ('.$GSi.' < $this->indentLevel) {
                '.$this->uNewE("indentCurrent", [ '$this->indentLevel' ], '$o', 'isset($e) ? $e : null').';
                ', '#NG', '
            } else {
                '.$this->uNewR('$o + '.$GSi, '""').';
                ', '#OK', '
            }
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function stringTable($name, $strings) {
        $table = [];
        foreach ($strings as $string) {
            $table[$string] = true;
        }
        $GSo = $this->uGensym('o');
        $GStable = $this->uGensym('table');
        $cil = $this->uFlatIL([ '
            /* stringTable */
            '.$GSo.' = $o;
            ', $this->uRef($name, $this->uFlatIL([ '
                if (!isset('.$GStable.')) {
                    '.$GStable.' = '.$this->uDump($table).';
                }
                if (isset('.$GStable.'[$v])) {
                    ', '#OK', '
                } else {
                    '.$this->uNewE("stringTable", [ $name, $strings ], $GSo, 'null').';
                    $o = '.$GSo.';
                    ', '#NG', '
                }
            ' ]), '#NG'), '
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    private function trans($okil) {
        $cil = $this->uFlatIL([ '
            /* trans */
            ', $this->uFlatIL($okil), '
            ', '#OK', '
        ' ]);
        $this->parsers[$this->currentName] = $this->uMergeIL($this->parsers[$this->currentName], $cil, '#NG');
        return $this;
    }

    /* Top */

    public static function generateCode() {
        $JinguGen = new JinguGen();

        $JinguGen->name('end')->end();
        $JinguGen->name('end_must')->end()->must()->trans([ '
            $v = "";
        ' ]);
        $JinguGen->name('newline_to_strip')->s("\n")->trans([ '
            $v = '.$JinguGen->uDump("<?\n?>").';
        ' ]);

        $JinguGen->name('indent_current')->indentCurrent();

        $JinguGen->name('blank_char')->s(" ");
        $JinguGen->name('blank_string')->repeat('blank_char', 0);
        $JinguGen->name('blank_line_unit_to_strip')->seq([ 'blank_string', 'newline_to_strip' ])->trans([ '
            $v = $v[1];
        ' ]);
        $JinguGen->name('blank_line_repeat_to_strip')->repeat('blank_line_unit_to_strip', 0)->trans([ '
            $v = implode("", $v);
        ' ]);

        $JinguGen->name('str_quoted')->phpString();

        $JinguGen->name('php_paren_open')->s("(");
        $JinguGen->name('php_paren_close')->s(")")->must();
        $JinguGen->name('php_brace_open')->s("{");
        $JinguGen->name('php_brace_close')->s("}")->must();
        $JinguGen->name('php_bracket_open')->s("[");
        $JinguGen->name('php_bracket_close')->s("]")->must();
        $JinguGen->name('php_paren_wrap')->seq([ 'php_paren_open', 'php_inner_repeat', 'php_paren_close' ])->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('php_brace_wrap')->seq([ 'php_brace_open', 'php_inner_repeat', 'php_brace_close' ])->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('php_bracket_wrap')->seq([ 'php_bracket_open', 'php_inner_repeat', 'php_bracket_close' ])->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('php_wrapping')->select([ 'php_paren_wrap', 'php_brace_wrap', 'php_bracket_wrap' ]);
        $JinguGen->name('php_normal')->byteNotTable("\"\'#`(){}[]");
        $JinguGen->name('php_inner_unit')->select([ 'str_quoted', 'php_wrapping', 'php_normal' ]);
        $JinguGen->name('php_inner_repeat')->repeat('php_inner_unit', 0)->trans([ '
            $v = implode("", $v);
        ' ]);

        $JinguGen->name('php_inattr_normal')->byteNotTable("\"\'#`(){}[] \n\t");
        $JinguGen->name('php_inattr_unit')->select(['str_quoted', 'php_wrapping', 'php_inattr_normal' ]);
        $JinguGen->name('php_inattr_repeat')->repeat('php_inattr_unit', 1)->trans([ '
            $v = implode("", $v);
        ' ]);

        $JinguGen->name('php_inoperator_normal')->byteNotTable("\"\'#`(){}[]\n");
        $JinguGen->name('php_inoperator_unit')->select(['str_quoted', 'php_wrapping', 'php_inoperator_normal' ]);
        $JinguGen->name('php_inoperator_repeat')->repeat('php_inoperator_unit', 0)->trans([ '
            $v = implode("", $v);
        ' ]);

        $JinguGen->name('attr_key_char')->byteNotTable(" \t\n\f\"\'/<=>");
        $JinguGen->name('attr_key_string')->repeat('attr_key_char', 1)->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('attr_key_name')->stringTable('attr_key_string', [
            'id',
        ]);

        $JinguGen->name('attr_separate_char')->s(" ");
        $JinguGen->name('attr_infix_char')->s("=");
        $JinguGen->name('attr_value_str')->attrString();
        $JinguGen->name('attr_pair_str')->seq([ 'attr_key_name', 'attr_infix_char', 'attr_value_str' ])->trans([ '
            $v = $v[0] . $v[1] . substr($v[2], 0, 1) . htmlspecialchars(substr($v[2], 1, -1), ENT_QUOTES, "UTF-8") . substr($v[2], -1);
        ' ]);
        $JinguGen->name('attr_pair_php')->seq([ 'attr_key_name', 'attr_infix_char', 'php_inattr_repeat' ])->trans([ '
            $v = $v[0] . \'="<?= htmlspecialchars((\' . $v[2] . \'), ENT_QUOTES, "UTF-8") ?>"\';
        ' ]);
        $JinguGen->name('attr_item')->select([ 'attr_pair_str', 'attr_pair_php', 'attr_key_name' ]);
        $JinguGen->name('attr_unit')->seq([ 'attr_separate_char', 'attr_item' ])->trans([ '
            $v = $v[1];
        ' ]);
        $JinguGen->name('attr_repeat')->repeat('attr_unit', 0)->trans([ '
            if (count($v) >= 1) {
                $v = " " . implode(" ", $v);
            } else {
                $v = "";
            }
        ' ]);

        $JinguGen->name('syntaxname_separator')->byteTable(" ");
        $JinguGen->name('syntaxname_char')->byteNotTable(" \n");
        $JinguGen->name('syntaxname_string')->repeat('syntaxname_char', 1)->trans([ '
            $v = implode("", $v);
        ' ]);

        $JinguGen->name('syntax_end_token_to_strip_must')->select([ 'newline_to_strip', 'end' ])->must();

        $JinguGen->name('htmltag_name_single')->stringTable('syntaxname_string', [
            'img',
            'br',
        ]);
        $JinguGen->name('htmltag_name_pair')->stringTable('syntaxname_string', [
            'div',
            'h2',
            'ul',
            'li',
            'a',
        ]);

        $JinguGen->name('syntax_indent')->indentNest(1, 'syntax_top_unit');
        $JinguGen->name('syntax_child_unit')->seq([ 'blank_line_repeat_to_strip', 'syntax_indent', 'blank_line_repeat_to_strip' ])->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('syntax_child_repeat')->repeat('syntax_child_unit', 0)->trans([ '
            $v = implode("", $v);
        ' ]);

        $JinguGen->name('htmltag_single')->seq([ 'htmltag_name_single', 'attr_repeat', 'syntax_end_token_to_strip_must' ])->trans([ '
            $v = "<" . $v[0] . $v[1] . ">" . $v[2];
        ' ]);
        $JinguGen->name('htmltag_pair')->seq([ 'htmltag_name_pair', 'attr_repeat', 'syntax_end_token_to_strip_must', 'syntax_child_repeat' ])->trans([ '
            $v = "<" . $v[0] . $v[1] . ">" . $v[2] . $v[3] . "</" . $v[0] . ">";
        ' ]);

        $JinguGen->name('operator_textout_char')->byteNotTable("\n");
        $JinguGen->name('operator_textout_string')->repeat('operator_textout_char', 0)->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('operator_textout_name')->stringTable('syntaxname_string', [
            '|',
        ]);
        $JinguGen->name('operator_textout_unit')->seq([ 'operator_textout_name', 'syntaxname_separator', 'operator_textout_string', 'syntax_end_token_to_strip_must' ])->trans([ '
            $v = htmlspecialchars($v[2], ENT_QUOTES, "UTF-8") . $v[3];
        ' ]);

        $JinguGen->name('operator_phpout_name')->stringTable('syntaxname_string', [
            '=',
        ]);
        $JinguGen->name('operator_phpout_unit')->seq([ 'operator_phpout_name', 'syntaxname_separator', 'php_inoperator_repeat', 'syntax_end_token_to_strip_must' ])->trans([ '
            $v = \'<?= htmlspecialchars((\' . $v[2] . \'), ENT_QUOTES, "UTF-8") ?>\' . $v[3];
        ' ]);

        $JinguGen->name('operator_comment_char')->byteNotTable("\n");
        $JinguGen->name('operator_comment_string')->repeat('operator_comment_char', 0)->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('operator_comment_name')->stringTable('syntaxname_string', [
            '//',
        ]);
        $JinguGen->name('operator_comment_unit')->seq([ 'operator_comment_name', 'operator_comment_string', 'syntax_end_token_to_strip_must' ])->trans([ '
            $v = "<? " . $v[0] . $v[1] . " ?>" . $v[2];
        ' ]);

        $JinguGen->name('operator_block_separator')->byteTable(" ");
        $JinguGen->name('operator_block_name')->stringTable('syntaxname_string', [
            'block',
        ]);
        $JinguGen->name('operator_block_unit')->seq([ 'operator_block_name', 'syntaxname_separator', 'str_quoted', 'operator_block_separator', 'php_paren_wrap', 'syntax_end_token_to_strip_must', 'syntax_child_repeat' ])->trans([ '
            $v = \'<? }, \' . $v[2] . \' => function \' . $v[4] . \' { ?>\' . $v[5] . $v[6];
        ' ]);

        $JinguGen->name('operator_braced_name')->stringTable('syntaxname_string', [
            'foreach',
        ]);
        $JinguGen->name('operator_braced_unit')->seq([ 'operator_braced_name', 'php_inoperator_repeat', 'syntax_end_token_to_strip_must', 'syntax_child_repeat' ])->trans([ '
            $v = "<? " . $v[0] . $v[1] . " { ?>" . $v[2] . $v[3] . "<? } ?>";
        ' ]);

        $JinguGen->name('operator_ifelse_name_if')->stringTable('syntaxname_string', [
            'if',
        ]);
        $JinguGen->name('operator_ifelse_name_elseif')->stringTable('syntaxname_string', [
            'elseif',
        ]);
        $JinguGen->name('operator_ifelse_name_else')->stringTable('syntaxname_string', [
            'else',
        ]);
        $JinguGen->name('operator_ifelse_if_unit')->seq([ 'operator_ifelse_name_if', 'php_inoperator_repeat', 'syntax_end_token_to_strip_must', 'syntax_child_repeat' ])->trans([ '
            $v = "<? " . $v[0] . $v[1] . " { ?>" . $v[2] . $v[3];
        ' ]);
        $JinguGen->name('operator_ifelse_elseif_unit')->seq([ 'indent_current', 'operator_ifelse_name_elseif', 'php_inoperator_repeat', 'syntax_end_token_to_strip_must', 'syntax_child_repeat' ])->trans([ '
            $v = "<? } " . $v[1] . $v[2] . " { ?>" . $v[3] . $v[4];
        ' ]);
        $JinguGen->name('operator_ifelse_elseif_repeat')->repeat('operator_ifelse_elseif_unit', 0)->trans([ '
            $v = implode("", $v);
        '] );
        $JinguGen->name('operator_ifelse_else_unit')->seq([ 'indent_current', 'operator_ifelse_name_else', 'syntax_end_token_to_strip_must', 'syntax_child_repeat' ])->trans([ '
            $v = "<? } " . $v[1] . " { ?>" . $v[2] . $v[3];
        ' ]);
        $JinguGen->name('operator_ifelse_else_optional')->repeat('operator_ifelse_else_unit', 0, 1)->trans([ '
            $v = implode("", $v);
        '] );
        $JinguGen->name('operator_ifelse_unit')->seq([ 'operator_ifelse_if_unit', 'operator_ifelse_elseif_repeat', 'operator_ifelse_else_optional' ])->trans([ '
            $v = implode("", $v) . "<? } ?>";
        ' ]);

        $JinguGen->name('syntax_select')->select([ 'htmltag_single', 'htmltag_pair', 'operator_textout_unit', 'operator_phpout_unit', 'operator_comment_unit', 'operator_block_unit', 'operator_braced_unit', 'operator_ifelse_unit' ]);
        $JinguGen->name('syntax_top_unit')->seq([ 'blank_line_repeat_to_strip', 'syntax_select', 'blank_line_repeat_to_strip' ])->trans([ '
            $v = implode("", $v);
        ' ]);
        $JinguGen->name('syntax_top_repeat')->repeat('syntax_top_unit', 0)->trans([ '
            $v = implode("", $v);
        ' ]);

        $JinguGen->name('top')->seq([ 'syntax_top_repeat', 'blank_line_repeat_to_strip', 'blank_string', 'end_must' ])->trans([ '
            $v = "<? return [ \"\" => function () { ?>" . $v[0] . $v[1] . "<? } ] ?>";
        ' ]);

        $stats = '';
        $bytes = 0;
        $counts = 0;
        foreach ($JinguGen->parsers as $name => $_) {
            if (isset($JinguGen->outputByteMap[$name])) {
                $stats .= sprintf("output %10d bytes %10d counts by %s\n", $JinguGen->outputByteMap[$name], $JinguGen->outputCountMap[$name], $name);
                $bytes += $JinguGen->outputByteMap[$name];
                $counts += $JinguGen->outputCountMap[$name];
            }
        }
        $stats .= sprintf("total %10d bytes %10d counts\n", $bytes, $counts);

        $code = '';
        $code .= '<?php'."\n";
        $code .= ''."\n";
        $code .= 'require_once "JinguParseException.php";'."\n";
        $code .= ''."\n";
        $code .= 'class JinguParser {'."\n";
        $code .= '    private $indentLevel = 0;'."\n";
        $code .= ''."\n";
        foreach ($JinguGen->parsers as $name => $il) {
            $il = $JinguGen->uMergeIL($il, $JinguGen->uReturn(), $JinguGen->uReturn());
            $lines = explode("\n", implode("\n", $il));
            $indent = 8;
            $code .= '    private function '.$name.'($t, $o) {'."\n";
            foreach ($lines as $line) {
                $line = ltrim($line, ' ');
                if ($line !== '') {
                    $fc = substr($line, 0, 1);
                    $lc = substr($line, -1);
                    if ($fc === '}' || $fc === ')') {
                        $indent -= 4;
                    }
                    $line = str_repeat(' ', $indent).$line;
                    if ($lc === '{' || $lc === '(') {
                        $indent += 4;
                    }
                }
                $code .= $line."\n";
            }
            $code .= '    }'."\n";
            $code .= "\n";
        }
        $code .= '    public function parseString($t) {'."\n";
        $code .= '        try {'."\n";
        $code .= '            if (mb_check_encoding($t, "UTF-8") === false) {'."\n";
        $code .= '                throw new JinguParseException($t, null, "Text encoding must be UTF-8");'."\n";
        $code .= '            }'."\n";
        $code .= '            if (strpos($t, '.$JinguGen->uDump("\r").') !== false) {'."\n";
        $code .= '                throw new JinguParseException($t, null, "Text EOL code must be LF");'."\n";
        $code .= '            }'."\n";
        $code .= '            list($o, $v, $e) = $this->top($t, 0);'."\n";
        $code .= '            return [ "value" => $v, "error" => $e ];'."\n";
        $code .= '        } catch (JinguParseException $ex) {'."\n";
        $code .= '            return [ "value" => null, "error" => [ "message" => $ex->errorMessage() ] ];'."\n";
        $code .= '        }'."\n";
        $code .= '    }'."\n";
        $code .= '}'."\n";
        $code = preg_replace('/\n\n+/', "\n\n", $code);
        return [ 'code' => $code, 'stats' => $stats ];
    }
}

$gen = JinguGen::generateCode();
fprintf(STDOUT, "%s", $gen['code']);
fprintf(STDERR, "%s", $gen['stats']);
