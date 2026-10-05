<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * No #[Entity] attribute — see ReviewReminderLogDefinition for why. Autoconfig
 * tags every EntityDefinition subclass with `shopware.entity.definition` and
 * EntityCompilerPass generates `<entity>.repository` from the entity name.
 */
class ReviewReminderConsentDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'topdata_product_review_reminder_consent';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ReviewReminderConsentCollection::class;
    }

    public function getEntityClass(): string
    {
        return ReviewReminderConsentEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),

            // IdField, not StringField: the migration declares binary(16) and
            // IdFieldSerializer converts to/from the binary representation.
            (new IdField('customer_id', 'customerId'))->addFlags(new Required()),

            // Rewritten on every re-grant, so it always marks the start of the
            // *current* consent period. That is the anchor the candidate filter
            // compares order_date_time against.
            (new DateTimeField('granted_at', 'grantedAt'))->addFlags(new Required()),

            // NULL while consent stands. Kept after revocation instead of being
            // nulled, so the withdrawal stays on record.
            (new DateTimeField('revoked_at', 'revokedAt')),
        ]);
    }
}