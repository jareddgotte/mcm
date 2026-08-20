<?php

declare(strict_types=1);

namespace Mcm\Tests;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * PHPUnit's half of the suite: discovery, selection and a report.
 *
 * There are no assertions here. Every one of them is in tests/cases.php,
 * written against the harness in tests/harness.php, and the dependency-free
 * runner in tests/run.php calls exactly the same closures. What this file adds
 * is the three things PHPUnit is for: each registered group becomes a named
 * test, the groups carry tags a caller can select on, and the run produces a
 * machine-readable report.
 *
 * One test method per requirement tag, because a PHPUnit group is a property of
 * a method and not of a data set: a caller asking for `--group quick` has to be
 * asking about methods. The tags themselves are declared by the cases - see
 * mcm_requirement_tags() - so the buckets here follow them rather than
 * describing them a second time.
 */
final class GroupTest extends TestCase
{
	/** Assertions that did not hold, as one line each, for the group running now. */
	private array $failures = [];

	/** Reasons the group running now could not run part of itself. */
	private array $skips = [];

	/** The recorder this test replaced, restored afterwards. */
	private $previousRecorder = null;

	/** Groups that only read the project's own files. */
	public static function sourceGroups(): array
	{
		return self::groupsTagged('source');
	}

	/** Groups that build a throw-away copy of the site and drive it as a child process. */
	public static function fixtureGroups(): array
	{
		return self::groupsTagged('fixture');
	}

	/** Groups that additionally run PHP's built-in server and talk to it. */
	public static function serverGroups(): array
	{
		return self::groupsTagged('server');
	}

	/** Groups that additionally need the optional, private database server. */
	public static function databaseGroups(): array
	{
		return self::groupsTagged('database');
	}

	#[Group('source')]
	#[Group('quick')]
	#[DataProvider('sourceGroups')]
	public function testSourceGroup(string $name): void
	{
		$this->runGroup($name);
	}

	#[Group('fixture')]
	#[Group('quick')]
	#[DataProvider('fixtureGroups')]
	public function testFixtureGroup(string $name): void
	{
		$this->runGroup($name);
	}

	#[Group('server')]
	#[Group('integration')]
	#[DataProvider('serverGroups')]
	public function testServerGroup(string $name): void
	{
		$this->runGroup($name);
	}

	#[Group('database')]
	#[Group('integration')]
	#[DataProvider('databaseGroups')]
	public function testDatabaseGroup(string $name): void
	{
		$this->runGroup($name);
	}

	/**
	 * The registered groups carrying one requirement tag, named by group name.
	 *
	 * Naming each data set after the group is what makes `--filter` here mean
	 * what `--filter=` means to the dependency-free runner.
	 *
	 * @return array<string, array{string}>
	 */
	private static function groupsTagged(string $tag): array
	{
		$named = [];
		foreach (mcm_groups() as $group) {
			if (in_array($tag, $group['tags'], true)) {
				$named[$group['name']] = [$group['name']];
			}
		}

		return $named;
	}

	protected function setUp(): void
	{
		$this->failures = [];
		$this->skips    = [];

		$this->previousRecorder = mcm_set_recorder([$this, 'record']);
	}

	protected function tearDown(): void
	{
		mcm_set_recorder($this->previousRecorder);
	}

	/**
	 * Run one registered group and report what it found.
	 *
	 * A failed assertion does not end the group here, any more than it does
	 * under the other runner, and that is deliberate rather than convenient: a
	 * group that dies half way through never reaches its own mcm_server_stop(),
	 * and an orphaned built-in server holds a port and the run's output pipe -
	 * which turns a failure into a hang. So every assertion is made, the ones
	 * that did not hold are collected, and the test fails at the end with all of
	 * them.
	 */
	private function runGroup(string $name): void
	{
		$group = null;
		foreach (mcm_groups() as $candidate) {
			if ($candidate['name'] === $name) {
				$group = $candidate;
				break;
			}
		}
		if ($group === null) {
			// Not an assertion: the name came from a provider reading the same
			// registry, so this cannot happen, and counting it would put the
			// two runners' assertion totals a test apart for nothing.
			throw new RuntimeException('no group is registered as "' . $name . '"');
		}

		$GLOBALS['mcm_state']['group'] = $name;
		$before = count($GLOBALS['mcm_state']['failures']);

		try {
			call_user_func($group['callback']);
		} catch (Throwable $throwable) {
			// Whatever happened, nothing this run started may outlive it.
			mcm_stop_all_servers();
			throw $throwable;
		}

		if (count($this->failures) > 0) {
			Assert::fail(implode("\n\n", $this->failures));
		}

		// A group that asserted nothing and said why is a skip, and the reason
		// is worth carrying into the report: for the optional database group it
		// is the whole notice, naming the coverage this run did not have.
		if (count($GLOBALS['mcm_state']['failures']) === $before
			&& $this->numberOfAssertionsPerformed() === 0
			&& count($this->skips) > 0
		) {
			$this->markTestSkipped(implode("\n", $this->skips));
		}
	}

	/**
	 * The harness's recorder: what an assertion, a skip and a note do here.
	 *
	 * Each assertion is made through PHPUnit so that it is counted as one, and
	 * the failure it throws is caught rather than propagated - see runGroup().
	 * PHPUnit counts an assertion before it evaluates it, so a caught failure is
	 * still a counted assertion and the totals stay comparable between runners.
	 */
	public function record(string $what, $a, $b = '', $c = ''): void
	{
		if ($what === 'assert') {
			try {
				Assert::assertTrue((bool) $a, (string) $b);
			} catch (AssertionFailedError $failure) {
				$this->failures[] = rtrim((string) $b . "\n" . (string) $c);
			}
			return;
		}

		if ($what === 'skip') {
			$this->skips[] = (string) $a . ' - ' . (string) $b;
			return;
		}

		// A note. PHPUnit prints what a test wrote, which is where the optional
		// group's long notice has to end up: a skip nobody reads is the thing
		// that notice exists to prevent.
		print rtrim((string) $a, "\n") . "\n";
	}
}
