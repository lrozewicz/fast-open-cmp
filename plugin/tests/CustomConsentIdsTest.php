<?php

use PHPUnit\Framework\TestCase;

/**
 * Custom consent ids must stay attached to the same consent: users' stored
 * choices are keyed by them (TC String publisher custom purpose N / cookie).
 */
final class CustomConsentIdsTest extends TestCase
{
    /** @return array{0: array, 1: int} */
    private function build(array $rows, array $previous = []): array
    {
        $admin = (new ReflectionClass(FOCMP_Admin::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionMethod(FOCMP_Admin::class, 'build_custom_consents');
        return $ref->invoke($admin, $rows, $previous);
    }

    private const PREVIOUS = [
        'customConsents' => [
            ['id' => 'custom-1', 'title' => 'Analytics'],
            ['id' => 'custom-2', 'title' => 'Marketing'],
            ['id' => 'custom-3', 'title' => 'Chat'],
        ],
    ];

    public function testNewTemplateNumbersFromOne(): void
    {
        [$consents, $next] = $this->build([
            'new-0' => ['id' => '', 'title' => 'A'],
            'new-1' => ['id' => '', 'title' => 'B', 'li' => '1'],
        ]);
        $this->assertSame(['custom-1', 'custom-2'], array_column($consents, 'id'));
        $this->assertSame([false, true], array_column($consents, 'legitimateInterestAllowed'));
        $this->assertSame(3, $next);
    }

    public function testDeletingAMiddleRowKeepsTheOtherIds(): void
    {
        // Row "Marketing" removed in the form; the LI flag travels with its row.
        [$consents] = $this->build([
            'custom-1' => ['id' => 'custom-1', 'title' => 'Analytics'],
            'custom-3' => ['id' => 'custom-3', 'title' => 'Chat', 'li' => '1'],
        ], self::PREVIOUS);
        $this->assertSame(['custom-1', 'custom-3'], array_column($consents, 'id'));
        $this->assertSame([false, true], array_column($consents, 'legitimateInterestAllowed'));
    }

    public function testDeletedNumbersAreNotReused(): void
    {
        $previous = self::PREVIOUS;
        $previous['customConsents'] = [['id' => 'custom-1', 'title' => 'Analytics']];
        $previous['customConsentsNextId'] = 4; // custom-2 and custom-3 were deleted earlier

        [$consents, $next] = $this->build([
            'custom-1' => ['id' => 'custom-1', 'title' => 'Analytics'],
            'new-0' => ['id' => '', 'title' => 'Newsletter'],
        ], $previous);
        $this->assertSame(['custom-1', 'custom-4'], array_column($consents, 'id'));
        $this->assertSame(5, $next);
    }

    public function testReorderingKeepsIds(): void
    {
        [$consents] = $this->build([
            'custom-3' => ['id' => 'custom-3', 'title' => 'Chat'],
            'custom-1' => ['id' => 'custom-1', 'title' => 'Analytics'],
        ], self::PREVIOUS);
        $this->assertSame(['custom-3', 'custom-1'], array_column($consents, 'id'));
    }

    public function testForgedOrDuplicateIdsGetFreshNumbers(): void
    {
        [$consents] = $this->build([
            'a' => ['id' => 'custom-1', 'title' => 'Analytics'],
            'b' => ['id' => 'custom-1', 'title' => 'Copy'],
            'c' => ['id' => 'custom-99', 'title' => 'Forged'],
            'd' => ['id' => '', 'title' => ''],
        ], self::PREVIOUS);
        $this->assertSame(['custom-1', 'custom-4', 'custom-5'], array_column($consents, 'id'));
    }
}
