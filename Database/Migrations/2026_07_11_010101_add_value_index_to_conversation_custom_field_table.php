<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddValueIndexToConversationCustomFieldTable extends Migration
{
    const INDEX_NAME = 'ccf_custom_field_id_value_index';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Table is owned by the CustomFields module and may not exist
        // or may have a different name depending on the module version.
        foreach (['conversation_custom_field', 'conversation_custom_fields'] as $ccf_table) {
            if (!Schema::hasTable($ccf_table)) {
                continue;
            }
            try {
                if (\Helper::isPgSql()) {
                    \DB::statement('CREATE INDEX '.self::INDEX_NAME.' ON '.$ccf_table.' (custom_field_id, value)');
                } else {
                    // Prefix length is required as `value` may be a TEXT column.
                    \DB::statement('ALTER TABLE `'.$ccf_table.'` ADD INDEX `'.self::INDEX_NAME.'` (`custom_field_id`, `value`(191))');
                }
            } catch (\Throwable $e) {
                \Log::warning('[Everymarket] Could not add index to '.$ccf_table.': '.$e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach (['conversation_custom_field', 'conversation_custom_fields'] as $ccf_table) {
            if (!Schema::hasTable($ccf_table)) {
                continue;
            }
            try {
                if (\Helper::isPgSql()) {
                    \DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
                } else {
                    \DB::statement('DROP INDEX `'.self::INDEX_NAME.'` ON `'.$ccf_table.'`');
                }
            } catch (\Throwable $e) {
                // Index may not exist.
            }
        }
    }
}
