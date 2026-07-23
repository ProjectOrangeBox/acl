<?php

define('__ROOT__', realpath(__DIR__ . '/../'));
define('__WWW__', realpath(__DIR__ . '/../htdocs'));

// two layouts: a standalone clone carries its own vendor/ directory; a clone
// developed in place inside an application's vendor tree finds its siblings
// two directories up (vendor/orange/*) and the autoloader three up
$standalone = is_dir(__DIR__ . '/../vendor');

$frameworkSrc = $standalone
    ? __DIR__ . '/../vendor/orange/framework/src'
    : __DIR__ . '/../../framework/src';

// Acl and its models (ConfigurationTrait, isUnique validation, Model/Sql) call
// logMsg()/isLogEnabled()/container() which are normally loaded at runtime by
// Application::preContainer() via dynamic include_once, not composer autoload -
// load them directly here so tests don't fatal on undefined functions. All three
// are safe to call without a booted container (they catch and no-op/return false).
require $frameworkSrc . '/helpers/helpers.php';
require $frameworkSrc . '/helpers/errors.php';
require $frameworkSrc . '/helpers/wrappers.php';

require $standalone
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/../../../autoload.php';

require __DIR__ . '/unitTestHelper.php';
