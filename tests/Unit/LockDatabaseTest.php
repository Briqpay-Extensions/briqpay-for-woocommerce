<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Lock;
use PHPUnit\Framework\TestCase;

/**
 * A stand-in for wpdb with the one property the lock depends on: option_name is
 * unique, so INSERT IGNORE creates the row for exactly one caller.
 */
class FakeLockWpdb
{
    public $options = 'wp_options';

    /** @var array<string,array{value:string,autoload:string}> */
    public $rows = array();

    /** @var string[] */
    public $queries = array();

    public function prepare($sql, ...$args)
    {
        return array($sql, $args);
    }

    public function query($prepared)
    {
        list($sql, $args) = $prepared;
        $this->queries[] = $sql;

        if (0 !== strpos($sql, 'INSERT IGNORE INTO wp_options')) {
            throw new \RuntimeException('Unexpected query: ' . $sql);
        }
        if (isset($this->rows[$args[0]])) {
            return 0;
        }
        $this->rows[$args[0]] = array('value' => $args[1], 'autoload' => 'no');
        return 1;
    }

    public function get_var($prepared)
    {
        list($sql, $args) = $prepared;
        $this->queries[] = $sql;

        return isset($this->rows[$args[0]]) ? $this->rows[$args[0]]['value'] : null;
    }

    public function delete($table, array $where)
    {
        $name = $where['option_name'];
        if (!isset($this->rows[$name])) {
            return 0;
        }
        if (isset($where['option_value']) && $this->rows[$name]['value'] !== $where['option_value']) {
            return 0;
        }
        unset($this->rows[$name]);
        return 1;
    }
}

/**
 * The lock against a real-shaped database, rather than the add_option() stubs.
 *
 * add_option() is not atomic in WordPress (it skips its existence check when the
 * option is in notoptions, upserts with ON DUPLICATE KEY UPDATE, and get_option()
 * caches the value for the rest of the request), so whenever $wpdb is available
 * the lock goes to the table directly.
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class LockDatabaseTest extends TestCase
{
    /** @var FakeLockWpdb */
    private $db;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->db = new FakeLockWpdb();
        $wpdb = $this->db;
    }

    public function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
        parent::tearDown();
    }

    public function testOnlyOneCallerWins(): void
    {
        $this->assertTrue(Lock::acquire('order_1', 30));
        $this->assertFalse(Lock::acquire('order_1', 30));
        $this->assertSame('no', $this->db->rows[Lock::PREFIX . md5('order_1')]['autoload']);
    }

    public function testTheClaimIsAnInsertIgnoreNotAnUpsert(): void
    {
        Lock::acquire('order_2', 30);

        $this->assertStringStartsWith('INSERT IGNORE INTO wp_options', $this->db->queries[0]);
        foreach ($this->db->queries as $sql) {
            $this->assertStringNotContainsString('ON DUPLICATE KEY', $sql);
        }
    }

    /**
     * The failure the waiting loop used to hit: another request releases the lock
     * and this one must see it on its next attempt, not a cached value.
     */
    public function testAWaiterSeesARelease(): void
    {
        $this->assertTrue(Lock::acquire('order_3', 30));
        $this->assertFalse(Lock::acquire('order_3', 30));

        // Released by the other request, directly in the table.
        unset($this->db->rows[Lock::PREFIX . md5('order_3')]);

        $this->assertTrue(Lock::acquire_wait('order_3', 30, 1));
    }

    public function testReleaseDeletesTheRow(): void
    {
        Lock::acquire('order_4', 30);
        Lock::release('order_4');

        $this->assertArrayNotHasKey(Lock::PREFIX . md5('order_4'), $this->db->rows);
        $this->assertFalse(Lock::is_held('order_4'));
    }

    public function testAnExpiredLockIsReclaimedOnce(): void
    {
        $this->db->rows[Lock::PREFIX . md5('order_5')] = array('value' => (string) (time() - 60), 'autoload' => 'no');

        $this->assertTrue(Lock::acquire('order_5', 30));
        $this->assertFalse(Lock::acquire('order_5', 30), 'The reclaimed lock is held again.');
    }

    /**
     * Two callers find the same expired lock; the second must not delete the
     * lock the first has just reclaimed.
     */
    public function testAReclaimNeverRemovesAFreshLock(): void
    {
        $option = Lock::PREFIX . md5('order_6');
        $this->db->rows[$option] = array('value' => (string) (time() + 30), 'autoload' => 'no');

        $method = new \ReflectionMethod(Lock::class, 'remove');
        $method->setAccessible(true);
        $method->invoke(null, $option, (string) (time() - 60));

        $this->assertArrayHasKey($option, $this->db->rows);
    }
}
