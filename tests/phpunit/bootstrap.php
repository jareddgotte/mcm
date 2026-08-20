<?php

/**
 * What PHPUnit loads before it looks for a test.
 *
 * The suite has no source to autoload: the site is the .php files in the
 * document root and loads nothing through Composer (see "Development tooling"
 * in AGENTS.md), and the cases drive throw-away copies of it as child processes
 * rather than including it. So this loads two things and nothing else: the
 * Composer autoloader, which exists here only for PHPUnit itself, and the same
 * harness and case files the dependency-free runner loads, in the same order.
 *
 * Loading tests/cases.php only registers closures; no case runs until a test
 * method calls one. That matters, because PHPUnit asks its data providers for
 * the group names before it runs anything, and the answer has to already be
 * there.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

require_once dirname(__DIR__) . '/harness.php';
require_once MCM_TESTS_DIR . '/entrypoints.php';
require_once MCM_TESTS_DIR . '/database.php';
require_once MCM_TESTS_DIR . '/mail.php';
require_once MCM_TESTS_DIR . '/cases.php';
