<?php

namespace Sanjid29\StarterCore\Services;

use Illuminate\Database\Eloquent\Model;

abstract class BaseService
{
    protected string $modelClass;

    /** Declare always-immutable fields in child services */
    protected array $immutable = [];

    public function getImmutableFields(Model $record): array
    {
        return $this->immutable;
    }

    // ── Hook stubs ───────────────────────────────────────────────────────
    protected function beforeCreate(array &$data): void {}

    protected function afterCreate(Model $record, array $data): void {}

    protected function beforeUpdate(Model $record, array &$data): void {}

    protected function afterUpdate(Model $record, array $data): void {}

    protected function beforeDelete(Model $record): void {}

    protected function afterDelete(Model $record): void {}

    // ── Core CRUD ────────────────────────────────────────────────────────
    public function create(array $data): Model
    {
        $this->beforeCreate($data);
        $record = $this->modelClass::create($data);
        $this->afterCreate($record, $data);

        return $record;
    }

    public function update(Model $record, array $data): Model
    {
        $this->beforeUpdate($record, $data);
        $this->stripImmutable($data);
        $record->update($data);
        $this->afterUpdate($record, $data);

        return $record;
    }

    public function delete(Model $record): void
    {
        $this->beforeDelete($record);
        $record->delete();
        $this->afterDelete($record);
    }

    // ── Immutable handling ───────────────────────────────────────────────
    private function stripImmutable(array &$data): void
    {
        foreach ($this->immutable as $field) {
            unset($data[$field]);
        }
    }
}
