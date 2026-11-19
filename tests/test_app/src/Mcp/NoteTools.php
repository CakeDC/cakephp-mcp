<?php
declare(strict_types=1);

namespace TestApp\Mcp;

use Cake\Validation\Validator;
use CakeDC\Mcp\Tool\ToolError;
use Mcp\Capability\Attribute\McpTool;

class NoteTools
{
    /**
     * Creates a note, after validating it the way a Cake table would.
     *
     * @return array<string, string>
     */
    #[McpTool(name: 'create_note')]
    public function create(string $title): array
    {
        $validator = (new Validator())->minLength('title', 3, 'Use at least 3 characters.');
        $errors = $validator->validate(['title' => $title]);
        if ($errors !== []) {
            throw ToolError::invalid($errors, 'The note was not created.');
        }

        return ['title' => $title];
    }
}
