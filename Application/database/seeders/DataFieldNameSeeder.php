<?php

namespace Database\Seeders;

use App\Models\DataFieldName;
use Illuminate\Database\Seeder;

class DataFieldNameSeeder extends Seeder
{
    /**
     * @return list<array{name: string, replace_code: string}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => '回線番号', 'replace_code' => 'caf_cop'],
            ['name' => '電話番号', 'replace_code' => 'tel_number'],
            ['name' => 'プロバイダアカウント', 'replace_code' => 'isp_account'],
            ['name' => 'プロバイダパスワード', 'replace_code' => 'isp_password'],
            ['name' => 'グローバル固定IPv4アドレス', 'replace_code' => 'isp_fixed_ipv4_address'],
            ['name' => 'インターフェースID', 'replace_code' => 'interface_id'],
            ['name' => 'AFTRアドレス', 'replace_code' => 'aftr_address'],
        ];
    }

    public function run(): void
    {
        foreach (self::defaults() as $row) {
            DataFieldName::query()->updateOrCreate(
                ['replace_code' => $row['replace_code']],
                [
                    'name' => $row['name'],
                    'is_active' => true,
                ]
            );
        }
    }
}
