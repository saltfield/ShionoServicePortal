<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // encrypted cast のペイロードは VARCHAR(1000) に収まらない
        DB::statement('ALTER TABLE contract_data MODIFY value TEXT NOT NULL');
        DB::statement('ALTER TABLE contract_item_data MODIFY value TEXT NOT NULL');

        $this->encryptPlaintextColumn('contract_data');
        $this->encryptPlaintextColumn('contract_item_data');
    }

    public function down(): void
    {
        $this->decryptCiphertextColumn('contract_data');
        $this->decryptCiphertextColumn('contract_item_data');

        DB::statement('ALTER TABLE contract_data MODIFY value VARCHAR(1000) NOT NULL');
        DB::statement('ALTER TABLE contract_item_data MODIFY value VARCHAR(1000) NOT NULL');
    }

    private function encryptPlaintextColumn(string $table): void
    {
        DB::table($table)->orderBy('id')->select(['id', 'value'])->chunkById(100, function ($rows) use ($table) {
            foreach ($rows as $row) {
                if ($row->value === null || $row->value === '') {
                    continue;
                }

                if ($this->isAlreadyEncrypted((string) $row->value)) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([
                    'value' => Crypt::encrypt((string) $row->value),
                ]);
            }
        });
    }

    private function decryptCiphertextColumn(string $table): void
    {
        DB::table($table)->orderBy('id')->select(['id', 'value'])->chunkById(100, function ($rows) use ($table) {
            foreach ($rows as $row) {
                if ($row->value === null || $row->value === '') {
                    continue;
                }

                try {
                    $plain = Crypt::decrypt((string) $row->value);
                } catch (Throwable) {
                    continue;
                }

                if (! is_string($plain)) {
                    $plain = (string) $plain;
                }

                DB::table($table)->where('id', $row->id)->update([
                    'value' => mb_substr($plain, 0, 1000),
                ]);
            }
        });
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decrypt($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
