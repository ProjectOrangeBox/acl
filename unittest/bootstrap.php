<?php

define('__ROOT__', realpath(__DIR__ . '/../'));
define('__WWW__', realpath(__DIR__ . '/../htdocs'));

// Acl and its models (ConfigurationTrait, isUnique validation, Model/Sql) call
// logMsg()/isLogEnabled()/container() which are normally loaded at runtime by
// Application::preContainer() via dynamic include_once, not composer autoload -
// load them directly here so tests don't fatal on undefined functions. All three
// are safe to call without a booted container (they catch and no-op/return false).
require __DIR__ . '/../vendor/orange/framework/src/helpers/helpers.php';
require __DIR__ . '/../vendor/orange/framework/src/helpers/errors.php';
require __DIR__ . '/../vendor/orange/framework/src/helpers/wrappers.php';

require __DIR__ . '/unitTestHelper.php';
