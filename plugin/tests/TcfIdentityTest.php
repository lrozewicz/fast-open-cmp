<?php

use PHPUnit\Framework\TestCase;

final class TcfIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['focmp_test_options']);
    }

    private function settings(array $settings): void
    {
        $GLOBALS['focmp_test_options']['focmp_settings'] = $settings;
    }

    public function testPlaceholderIdsAreNotRegistered(): void
    {
        foreach ([[], ['cmp_id' => 0], ['cmp_id' => 28]] as $settings) {
            $this->settings($settings);
            $this->assertFalse(FOCMP_TCF::has_registered_cmp_id());
            $this->assertSame(28, FOCMP_TCF::cmp_id());
        }
        $this->settings(['cmp_id' => 412]);
        $this->assertTrue(FOCMP_TCF::has_registered_cmp_id());
        $this->assertSame(412, FOCMP_TCF::cmp_id());
    }

    public function testCmpVersionFollowsThePluginUnlessPinned(): void
    {
        $this->settings(['cmp_version' => 1]); // old default = "auto"
        $this->assertSame(120, FOCMP_TCF::cmp_version());
        $this->settings(['cmp_version' => 7]);
        $this->assertSame(7, FOCMP_TCF::cmp_version());
    }

    public function testVersionMapping(): void
    {
        $this->assertSame(120, FOCMP_TCF::version_to_cmp_version('1.2.0'));
        $this->assertSame(134, FOCMP_TCF::version_to_cmp_version('1.3.4'));
        $this->assertSame(200, FOCMP_TCF::version_to_cmp_version('2.0'));
        $this->assertSame(2, FOCMP_TCF::version_to_cmp_version('0.0.1'));
    }
}
