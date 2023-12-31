<?php declare(strict_types=1);

namespace WeArePlanetPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Class Migration1605701048TransactionEntity
 *
 * @package WeArePlanetPayment\Migration
 */
class Migration1605701048TransactionEntity extends MigrationStep
{

    /**
     * get creation timestamp
     *
     * @return int
     */
    public function getCreationTimestamp(): int
    {
        return 1605701048;
    }

    /**
     * update non-destructive changes
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @throws \Doctrine\DBAL\DBALException
     */
    public function update(Connection $connection): void
    {

        try {
            $connection->executeUpdate('
                ALTER TABLE `weareplanet_transaction`
                    ADD `order_version_id` binary(16) NOT NULL AFTER `transaction_id`;
            ');

            $connection->executeUpdate('
                UPDATE `weareplanet_transaction` t1
                    INNER JOIN `order` t2
                        ON t1.order_id = t2.id
                    SET t1.order_version_id = t2.version_id;
            ');

            $connection->executeUpdate('
                ALTER TABLE `weareplanet_transaction`
                    DROP FOREIGN KEY `fk.pln_transaction.order_id`,
                    DROP FOREIGN KEY `fk.pln_transaction.order_transaction_id`,
                    DROP FOREIGN KEY `fk.pln_transaction.payment_method_id`,
                    DROP FOREIGN KEY `fk.pln_transaction.sales_channel_id`;
            ');

            $connection->executeUpdate('
                ALTER TABLE `weareplanet_transaction`
                    ADD CONSTRAINT `fk.pln_transaction_order_id` FOREIGN KEY (`order_id`, `order_version_id`)
                        REFERENCES `order` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    ADD CONSTRAINT `fk.pln_transaction_payment_method_id` FOREIGN KEY (`payment_method_id`)
                        REFERENCES `payment_method` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                    ADD CONSTRAINT `fk.pln_transaction_sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                        REFERENCES `sales_channel` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT;
            ');
        } catch (\Exception $exception) {
            // column probably exists
        }
    }

    /**
     * update destructive changes
     *
     * @param \Doctrine\DBAL\Connection $connection
     */
    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
