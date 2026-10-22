<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testLivenessDoesNotExposeInfrastructureDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');
        self::assertResponseIsSuccessful();
        self::assertSame('ok', $client->getResponse()->getContent());
    }

    public function testLivenessRejectsWrites(): void
    {
        self::createClient()->request('POST', '/healthz');
        self::assertResponseStatusCodeSame(405);
    }
}
