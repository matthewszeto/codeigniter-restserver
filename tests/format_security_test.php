<?php

/**
 * Behavioral checks for Format. The REST controller needs CodeIgniter, which
 * this repository does not ship, so those changes are syntax-checked only.
 *
 * Run: php tests/format_security_test.php
 */

define('BASEPATH', __DIR__);

$failures = 0;

function expect($condition, $message)
{
    global $failures;
    if ($condition) {
        echo "ok  $message\n";
        return;
    }

    $failures++;
    echo "FAIL $message\n";
}

function singular($str)
{
    return $str;
}

class FakeTable
{
    public $headings = [];
    public $rows = [];

    public function set_heading($headings)
    {
        $this->headings = $headings;
    }

    public function add_row($row)
    {
        $this->rows[] = $row;
    }

    public function generate()
    {
        $html = '<table>';
        foreach ($this->rows as $row) {
            foreach ((array) $row as $cell) {
                $html .= '<td>'.$cell.'</td>';
            }
        }

        return $html.'</table>';
    }
}

class FakeInput
{
    public $callback = null;

    public function get($key = null)
    {
        if ($key === 'callback') {
            return $this->callback;
        }

        return null;
    }
}

class FakeLoad
{
    public $ci;

    public function __construct($ci)
    {
        $this->ci = $ci;
    }

    public function helper($name)
    {
    }

    public function library($name)
    {
        if ($name === 'table') {
            $this->ci->table = new FakeTable();
        }
    }
}

class FakeCI
{
    public $load;
    public $input;
    public $table;

    public function __construct()
    {
        $this->load = new FakeLoad($this);
        $this->input = new FakeInput();
    }
}

function &get_instance()
{
    static $ci;
    if ($ci === null) {
        $ci = new FakeCI();
    }

    return $ci;
}

require __DIR__.'/../application/libraries/Format.php';

class Evil
{
    public static $woke = false;

    public function __wakeup()
    {
        self::$woke = true;
    }
}

$ci = get_instance();

// Serialized arrays are data. Serialized objects are not.
try {
    $format = new Format(serialize(['id' => 1, 'name' => 'Ada']), 'serialize');
    $parsed = $format->to_array();
    expect(isset($parsed['name']) && $parsed['name'] === 'Ada', 'serialized array round-trips');
} catch (Exception $ex) {
    expect(false, 'serialized array round-trips ('.$ex->getMessage().')');
}

try {
    new Format('b:0;', 'serialize');
    expect(true, 'serialized false is not treated as a parse error');
} catch (Exception $ex) {
    expect(false, 'serialized false is not treated as a parse error');
}

$objectPayloads = [
    'object' => serialize(new Evil()),
    'nested object' => serialize(['user' => new Evil()]),
    'stdClass' => serialize(new stdClass()),
];

foreach ($objectPayloads as $label => $payload) {
    Evil::$woke = false;
    $threw = false;
    try {
        new Format($payload, 'serialize');
    } catch (Exception $ex) {
        $threw = true;
    }
    expect($threw, "serialized $label is rejected");
    expect(Evil::$woke === false, "serialized $label does not wake the class");
}

// XML: doctypes are rejected, ordinary documents still parse.
$secret = tempnam(sys_get_temp_dir(), 'xxe');
file_put_contents($secret, 'xxe-secret-marker');
$xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file://'.$secret.'">]><foo>&xxe;</foo>';
$threw = false;
$leaked = false;
try {
    $parsed = (new Format($xxe, 'xml'))->to_array();
    $leaked = strpos(json_encode($parsed), 'xxe-secret-marker') !== false;
} catch (Exception $ex) {
    $threw = true;
}
expect($threw && $leaked === false, 'XML doctype is rejected and the file is not read');
unlink($secret);

try {
    $parsed = (new Format('<?xml version="1.0"?><user><name>Ada</name></user>', 'xml'))->to_array();
    $encoded = json_encode($parsed);
    expect(strpos($encoded, 'Ada') !== false, 'ordinary XML still parses');
} catch (Exception $ex) {
    expect(false, 'ordinary XML still parses ('.$ex->getMessage().')');
}

$format = new Format(null);
$xml = $format->to_xml(['count' => 2, 'name' => 'a<b']);
expect(is_string($xml) && strpos($xml, '<b>') === false, 'XML text cannot open a new element');
expect(strpos($xml, '2') !== false, 'XML output accepts integer values');

$hostile = $format->to_xml(['a' => 'b'], null, 'foo><injected');
expect(strpos($hostile, '><injected') === false, 'XML root name cannot break out of the element');
expect(strpos($hostile, 'fooinjected') !== false, 'XML root name keeps the safe characters');

$html = $format->to_html([
    ['name' => '<script>alert(1)</script>', 'note' => 'a"b'],
]);
expect(strpos($html, '<script>') === false, 'HTML cells are escaped');
expect(strpos($html, '&lt;script&gt;') !== false, 'HTML cells keep the escaped text');
expect(strpos($html, 'a&quot;b') !== false, 'HTML quotes are escaped');

$csv = $format->to_csv([
    ['formula' => '=1+1', 'negative' => -12, 'plus' => '+cmd', 'safe' => 'ok'],
]);
expect(strpos($csv, "'=1+1") !== false, 'CSV formulas are prefixed');
expect(strpos($csv, "'-12") === false && strpos($csv, '-12') !== false, 'numeric CSV values stay numeric');
expect(strpos($csv, "'+cmd") !== false, 'CSV cells starting with + are prefixed');

$ci->input->callback = null;
$plain = $format->to_json(['id' => 1]);
expect($plain === '{"id":1}', 'JSON without a callback is JSON');

$ci->input->callback = 'keep';
$wrapped = $format->to_json(['id' => 1]);
expect($wrapped === 'keep({"id":1});', 'a valid JSONP callback is wrapped');

$ci->input->callback = '</script><script>alert(1)</script>';
$list = $format->to_json([['id' => 1]]);
expect(strpos($list, '<script>') === false, 'an invalid callback is not echoed into a list');
expect($list === '[{"id":1}]', 'a list payload keeps its shape when the callback is invalid');

$object = $format->to_json(['id' => 1]);
expect(strpos($object, '<script>') === false, 'an invalid callback is not echoed into an object');
expect(strpos($object, 'Invalid JSONP callback.') !== false, 'an object payload records that the callback was rejected');

$ci->input->callback = null;

if ($failures > 0) {
    echo "\n$failures failed\n";
    exit(1);
}

echo "\nall passed\n";
exit(0);
