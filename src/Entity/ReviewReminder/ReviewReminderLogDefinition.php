<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * NOTE: no #[Entity] attribute here on purpose.
 *
 * In Shopware 6.7 `#[Entity]` is a TARGET_CLASS attribute that belongs on the
 * Entity subclass and is compiled by AttributeEntityCompilerPass into a
 * generic AttributeEntityDefinition. Putting it on the Definition instead makes
 * that pass overwrite `<entity>.definition` with a field-less instance, and the
 * failure only shows up later as "Field orderId ... was not found".
 *
 * Autoconfiguration tags every EntityDefinition subclass with
 * `shopware.entity.definition`; EntityCompilerPass then derives the entity name
 * and generates `<entity>.repository` from it.
 */
class ReviewReminderLogDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'topdata_product_review_reminder_log';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ReviewReminderLogCollection::class;
    }

    public function getEntityClass(): string
    {
        return ReviewReminderLogEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),

            // IdField, not StringField: the migration declares binary(16) and
            // IdFieldSerializer converts to/from the binary representation.
            (new IdField('order_id', 'orderId'))->addFlags(new Required()),
            (new IdField('order_version_id', 'orderVersionId'))->addFlags(new Required()),
            (new IdField('customer_id', 'customerId'))->addFlags(new Required()),
            (new IdField('sales_channel_id', 'salesChannelId'))->addFlags(new Required()),

            (new StringField('email', 'email'))->addFlags(new Required()),

            // JSON array of product ids, stored as TEXT. There is no TextField
            // in the DAL; LongTextField is the unbounded one. A JsonField has
            // no distinct null/[] behaviour on write, a TextField-style column
            // with an explicit json_encode in the log service does.
            (new LongTextField('review_product_ids', 'reviewProductIds')),

            // NULL while the row is only a claim, set once the mail is away.
            // The "already reminded" pre-filter only honours non-NULL values so
            // a crashed run stays eligible on the next pass.
            (new DateTimeField('sent_at', 'sentAt')),
        ]);
    }
}