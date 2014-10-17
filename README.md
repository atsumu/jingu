# jingu

Jingu Template Engine

## Setup

Run 'gen.bash' to generate 'JinguParser.php'.

```
bash gen.bash
```

## Usage

sample.php

```
require_once 'JinguParser.php';
require_once 'JinguUtil.php';

class SampleJinguUtil extends JinguUtil {
    protected function templateDirectory() {
        return __DIR__;
    }
    protected function cacheDirectory() {
        return __DIR__;
    }
}

$jinguUtil = new SampleJinguUtil(new JinguParser());
$jinguUtil->call('sample', 'sample', [ [ 1, 3, 5 ] ]);
```

sample.jingu

```
block "sample" ($numbers)
 div id="contents"
  foreach ($numbers as $number)
   div
    = $number
```

output

```
<div id="contents"><div>1</div><div>3</div><div>5</div></div>
```
