<?php

namespace Tests\Unit;

use App\Support\Countries;
use Tests\TestCase;

class CountriesTest extends TestCase
{
    public function test_the_list_has_249_countries_once_each(): void
    {
        $this->assertCount(249, Countries::CODES);
        $this->assertCount(249, array_unique(Countries::CODES));
        $sorted = Countries::CODES;
        sort($sorted);
        $this->assertSame($sorted, Countries::CODES);
        foreach (Countries::CODES as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
        }
        $this->assertTrue(Countries::valid('TR'));
        $this->assertFalse(Countries::valid('XK'));
        // Registries also write these; they are not countries.
        $this->assertFalse(Countries::valid('EU'));
        $this->assertFalse(Countries::valid('AP'));
        $this->assertFalse(Countries::valid('ZZ'));
        $this->assertFalse(Countries::valid('tr'));
        $this->assertFalse(Countries::valid(''));
    }

    public function test_find_matches_code_and_name(): void
    {
        app()->setLocale('tr');
        $this->assertSame('Rusya', Countries::name('RU'));
        // The code first, then the names that hold the letters.
        $this->assertSame('RU', Countries::find('ru')[0]);
        $this->assertContains('PE', Countries::find('ru'));
        $this->assertSame(['RU'], Countries::find(' Rusy '));
        // A name that starts with the letters comes before one that holds them.
        $mal = Countries::find('mal');
        $this->assertSame(['MW', 'MV', 'MY', 'ML', 'MT'], array_slice($mal, 0, 5));
        $this->assertContains('SO', $mal);
        $this->assertContains('GT', $mal);
        $this->assertContains('BY', Countries::find('rus'));
        $this->assertContains('RU', Countries::find('rus'));
        $this->assertSame([], Countries::find(''));
        $this->assertSame([], Countries::find('qqqq'));

        app()->setLocale('en');
        $this->assertSame(['RU'], Countries::find('russia'));
        $this->assertSame([], Countries::find('rusya'));
    }

    public function test_a_turkish_name_is_found_however_its_i_is_typed(): void
    {
        app()->setLocale('tr');
        // "İsveç", "İsviçre": a capital dotted I that people type as a plain i.
        $this->assertContains('SE', Countries::find('isveç'));
        $this->assertContains('SE', Countries::find('İSVEÇ'));
        $this->assertContains('IQ', Countries::find('ırak'));
        $this->assertContains('IQ', Countries::find('irak'));
    }

    public function test_names_come_out_in_the_order_of_the_readers_alphabet(): void
    {
        app()->setLocale('tr');
        $found = Countries::find('land');
        $names = array_map(Countries::name(...), $found);
        $sorted = $names;
        collator_sort(collator_create('tr'), $sorted);
        $this->assertSame($sorted, $names);
        $this->assertGreaterThan(3, count($found));
    }
}
