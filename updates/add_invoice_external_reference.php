<?php namespace Responsiv\Pay\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddInvoiceExternalReference extends Migration
{
    public function up()
    {
        Schema::table('responsiv_pay_invoices', function($table)
        {
            $table->string('external_reference')->index()->nullable();
        });
    }

    public function down()
    {
    }
}
