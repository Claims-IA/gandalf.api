<?php
/**
 * ExcelErrorTranslator
 *
 * Turns Lumen validation errors (keyed by dot-path, e.g.
 * "variants.2.rules.3.conditions.1.value") into cell-addressed, French,
 * user-facing error entries using the cell map recorded by ExcelTableReader.
 *
 * The reader's cell map is relative to a payload where the imported variant
 * sits at index 0 ("variants.0.*"); in an update payload the target variant
 * may be at another index, so paths are rebased before lookup. Errors whose
 * dot-path targets a variant NOT included in the file (pre-existing invalid
 * data) are kept but flagged so the user understands the file is not at fault.
 *
 * @package App\Services\Excel
 */

namespace App\Services\Excel;

class ExcelErrorTranslator
{
    /**
     * @param  array             $lumenErrors        Validator errors: dot-path => [messages].
     * @param  ExcelImportResult $result             Parsed workbook (cell/column maps).
     * @param  int               $targetVariantIndex Index of the imported variant in the payload.
     * @param  array             $variantTitles      Payload variant index => title (for off-file errors).
     * @return array  List of ['cell','row','column','field','message'] entries.
     */
    public function translate(array $lumenErrors, ExcelImportResult $result, int $targetVariantIndex = 0, array $variantTitles = []): array
    {
        $entries = [];

        foreach ($lumenErrors as $path => $messages) {
            $messages = (array) $messages;

            // Rebase "variants.{target}.x" onto the reader's "variants.0.x" map
            $mapPath = $path;
            if (preg_match('/^variants\.(\d+)\.(.*)$/', $path, $m)) {
                $variantIdx = (int) $m[1];
                if ($variantIdx === $targetVariantIndex) {
                    $mapPath = 'variants.0.' . $m[2];
                } else {
                    // Error in a variant that is not in the file
                    $label = $variantTitles[$variantIdx] ?? ('#' . ($variantIdx + 1));
                    foreach ($messages as $message) {
                        $entries[] = [
                            'cell' => null, 'row' => null, 'column' => null, 'field' => null,
                            'message' => "Variante \"$label\" (non incluse dans le fichier) : "
                                . $this->frenchify($path, $message, $result),
                        ];
                    }
                    continue;
                }
            }

            $cell = $result->cellMap[$mapPath] ?? null;
            [$column, $row] = $this->splitCell($cell);
            $fieldKey = $this->fieldKeyForPath($mapPath, $result);

            foreach ($messages as $message) {
                $prefix = '';
                if ($cell !== null) {
                    $prefix = "Ligne $row, colonne $column" . ($fieldKey ? " ($fieldKey)" : '') . ' : ';
                }
                $entries[] = [
                    'cell' => $cell,
                    'row' => $row,
                    'column' => $column,
                    'field' => $fieldKey,
                    'message' => $prefix . $this->frenchify($mapPath, $message, $result),
                ];
            }
        }

        return $entries;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function splitCell(?string $cell): array
    {
        if ($cell !== null && preg_match('/^([A-Z]+)(\d+)$/', $cell, $m)) {
            return [$m[1], (int) $m[2]];
        }
        return [null, null];
    }

    /**
     * Recover the field key for a condition dot-path so the message can name
     * the column ("colonne C (age)").
     */
    private function fieldKeyForPath(string $mapPath, ExcelImportResult $result): ?string
    {
        if (preg_match('/^variants\.0\.rules\.\d+\.conditions\.(\d+)\./', $mapPath, $m)) {
            // Conditions are emitted in field order — index maps to fields[]
            return $result->fields[(int) $m[1]]['key'] ?? null;
        }
        if (preg_match('/^fields\.(\d+)\./', $mapPath, $m)) {
            return $result->fields[(int) $m[1]]['key'] ?? null;
        }
        return null;
    }

    /**
     * Replace the most common English Lumen messages (and the terse custom
     * rule names) with actionable French wording. Unknown messages pass through.
     */
    private function frenchify(string $path, string $message, ExcelImportResult $result): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'condition type')) {
            return 'la valeur n\'est pas compatible avec l\'opérateur (les opérateurs >, >=, <, <= et les intervalles attendent des nombres).';
        }
        if (str_contains($lower, 'rule than type')) {
            return 'la décision n\'est pas compatible avec le decision_type de la table'
                . ($result->decisionType !== '' ? ' (' . $result->decisionType . ')' : '') . '.';
        }
        if (str_contains($lower, 'conditions count')) {
            return 'la règle ne couvre pas tous les champs de la table (utilisez * pour "peu importe").';
        }
        if (str_contains($lower, 'decision type')) {
            return 'decision_type doit être "numeric" pour une table de type scoring.';
        }
        if (str_contains($lower, 'probability sum')) {
            return 'la somme des probabilités des variantes doit faire exactement 100.';
        }
        if (str_ends_with($path, '.key') && str_contains($lower, 'invalid')) {
            return 'clé de champ réservée ou invalide (interdits : variant_id, decision, _rule_id, rule_title, rule_description).';
        }
        if (str_contains($lower, 'required')) {
            return 'valeur obligatoire manquante.';
        }
        if (str_contains($lower, 'must be a number') || str_contains($lower, 'numeric')) {
            return 'valeur numérique attendue.';
        }
        if (str_contains($lower, 'invalid') && str_contains($path, 'matching_type')) {
            return 'matching_type invalide (feuille _meta) — valeurs acceptées : first, scoring_sum, scoring_max, scoring_min, scoring_count.';
        }

        return $message;
    }
}
