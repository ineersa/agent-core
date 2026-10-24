<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One guided settings-list field for a curated extension.
 *
 * Catalog metadata only — label/default/type/path. Validation of submitted
 * values lives on the setup form DTO, not here.
 */
final readonly class ProvidedExtensionGuidedFieldDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $id,
        #[Assert\NotBlank]
        public string $path,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [
            'bool',
            'csv_list',
            'absolute_path_list',
            'path_string',
            'positive_int',
            'open_unit_ratio',
            'model_id',
        ])]
        public string $type,
        #[Assert\NotBlank]
        public string $label,
        #[Assert\NotBlank]
        public string $description,
        public mixed $default = null,
    ) {
    }
}
