<?php
/** @var \Stanford\ProjMeGc\ProjMeGc $module */

echo "Hello";


// This isn't used anymore - remove:

/*
      {
        "name": "ME GC EMA",
        "icon": "gear",
        "url": "test.php"
      }
*/


exit();

$l = \ExternalModules\ExternalModules::getModuleInstance('em_logger');

$l->emLogger("test", array("line 11 of test.php", "banana"), "DEBUG");

function debug2($test) {
    // $p = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
    global $l;
    $l->log("test", $test, "DEBUG");
}

debug2("line 15  (or 18) of test.php in function log");


function f1 ($v1) {
    return f2($v1);
}


function f2($v2) {
    global $module;
    $module->log("IN F2 ON LINE 21");
    return $v2.$v2;
}
$a = f1("FFF");

exit();

usleep(100);

$module->log("LINE 23 OF INDEX", array("ARRAY OF LINE 23 OF INDEX"));

usleep(100);

$module->one('LINE 27 of INDEX CALLING FUNCTION one');


echo "Done";
