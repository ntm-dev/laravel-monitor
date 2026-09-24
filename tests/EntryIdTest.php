<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Support\EntryId;

class EntryIdTest extends TestCase
{
    use RefreshDatabase;


    public function test_encode_then_decode_round_trips_back_to_the_original_id(): void
    {
        foreach ([1, 42, 999999, 1574877, 4294967295] as $id) {
            $this->assertSame($id, EntryId::decode(EntryId::encode($id)));
        }
    }

    public function test_encoded_id_is_uuid_shaped(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', EntryId::encode(1574877));
    }

    public function test_two_different_ids_never_encode_to_the_same_string(): void
    {
        $this->assertNotSame(EntryId::encode(1), EntryId::encode(2));
    }

    public function test_decode_rejects_a_value_that_is_not_uuid_shaped(): void
    {
        $this->assertNull(EntryId::decode('1574877'));
        $this->assertNull(EntryId::decode('not-a-uuid'));
        $this->assertNull(EntryId::decode(''));
    }
}
