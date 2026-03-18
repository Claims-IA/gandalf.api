<?php
/**
 * ConditionType Model
 *
 * Placeholder MongoDB model for the 'condition_types' collection. In the current
 * implementation condition type definitions are managed in code by the
 * ConditionsTypes service rather than stored in the database, so this model has
 * no methods of its own. It is retained to allow future migration of condition
 * type metadata (labels, descriptions, allowed field types) into MongoDB without
 * changing the service layer.
 *
 * @package App\Models
 */

namespace App\Models;

class ConditionType extends Base
{

}
