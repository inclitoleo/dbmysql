<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Security;

use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Security\IdentifierValidator;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use PHPUnit\Framework\TestCase;

final class IdentifierValidatorTest extends TestCase
{
    /**
     * @dataProvider validIdentifiers
     */
    public function testAcceptsValidIdentifiers(string $identifier): void
    {
        $this->assertTrue(IdentifierValidator::isValid($identifier));
        IdentifierValidator::requireValid($identifier);
    }

    /**
     * @return list<list<string>>
     */
    public static function validIdentifiers(): array
    {
        return [
            ['account'],
            ['id'],
            ['_tmp'],
            ['Account_1'],
        ];
    }

    /**
     * @dataProvider invalidIdentifiers
     */
    public function testRejectsInvalidIdentifiers(string $identifier, string $reasonFragment): void
    {
        $this->assertFalse(IdentifierValidator::isValid($identifier));
        try {
            IdentifierValidator::requireValid($identifier);
            $this->fail('Expected InvalidIdentifierException');
        } catch (InvalidIdentifierException $e) {
            $this->assertSame($identifier, $e->getIdentifier());
            $this->assertStringContainsString($reasonFragment, $e->getReason());
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function invalidIdentifiers(): array
    {
        return [
            ['account; DROP', 'semicolon'],
            ['account name', 'whitespace'],
            ['', 'empty'],
            ['account-name', 'must match'],
            ['1account', 'must match'],
            ['account.users', 'must match'],
        ];
    }

    public function testSchemaRegistryAcceptsRegisteredTableAndColumn(): void
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id', 'name', 'email']);

        $this->assertTrue($schema->hasTable('account'));
        $this->assertTrue($schema->hasColumn('account', 'email'));
        $schema->requireTable('account');
        $schema->requireColumn('account', 'name');
        $this->assertSame(['id', 'name', 'email'], $schema->columns('account'));
        $this->assertSame(['account'], $schema->tables());
    }

    public function testSchemaRegistryRejectsUnregisteredTable(): void
    {
        $schema = new SchemaRegistry();
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('users; DROP TABLE account');
        $schema->requireTable('users; DROP TABLE account');
    }

    public function testSchemaRegistryRejectsUnregisteredColumn(): void
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id', 'name', 'email']);
        try {
            $schema->requireColumn('account', 'password_hash');
            $this->fail('Expected InvalidIdentifierException');
        } catch (InvalidIdentifierException $e) {
            $this->assertSame('password_hash', $e->getIdentifier());
        }
    }

    public function testRegisterRejectsInvalidIdentifierEvenIfCallerTriesToWhitelist(): void
    {
        $schema = new SchemaRegistry();
        $this->expectException(InvalidIdentifierException::class);
        $schema->register('account; DROP', ['id']);
    }

    public function testRegisterMergesColumns(): void
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id']);
        $schema->register('account', ['name', 'id']);
        $this->assertSame(['id', 'name'], $schema->columns('account'));
    }
}
