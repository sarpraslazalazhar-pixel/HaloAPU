import React from 'react';
import { FormField } from '@/types';
import FieldRenderer from '@/Components/FormBuilder/FieldRenderer';

export type DynamicFieldValue = string | number | boolean | string[] | File | File[] | null | undefined;

interface DynamicFieldProps {
  fields: FormField[];
  values: Record<string, DynamicFieldValue>;
  onChange: (fieldId: number, value: DynamicFieldValue) => void;
  errors?: Record<string, string>;
}

// Exported: stable domain concept used in Wizard.tsx and here (>2 call sites across files).
export const normalizeOption = (v: unknown): string =>
  String(v ?? '').trim().toLowerCase().replace(/[:\s]+$/, '');

export default function DynamicField({ fields, values, onChange, errors }: DynamicFieldProps) {
  const isFieldVisible = (field: FormField) => {
    if (!field.parent_field_id) return true;
    // parent_field_id from backend may arrive as number; form_data keys are stored as string(number).
    const parentValue = values[String(field.parent_field_id)];
    if (parentValue === undefined || parentValue === null) return false;
    const trigger = normalizeOption(field.trigger_value);
    if (Array.isArray(parentValue)) {
      return parentValue.some(v => normalizeOption(v) === trigger);
    }
    return normalizeOption(parentValue) === trigger;
  };

  // Urutkan field agar cabang/child langsung berada tepat di bawah parent-nya
  const orderedFields: FormField[] = [];
  const rootFields = fields.filter(f => !f.parent_field_id);
  const childMap = new Map<number, FormField[]>();

  fields.forEach(f => {
    if (f.parent_field_id) {
      const pid = Number(f.parent_field_id);
      if (!childMap.has(pid)) childMap.set(pid, []);
      childMap.get(pid)!.push(f);
    }
  });

  const appendFieldAndChildren = (field: FormField) => {
    orderedFields.push(field);
    (childMap.get(Number(field.id)) ?? []).forEach(appendFieldAndChildren);
  };

  rootFields.forEach(appendFieldAndChildren);

  const addedIds = new Set(orderedFields.map(f => Number(f.id)));
  fields.forEach(f => { if (!addedIds.has(Number(f.id))) orderedFields.push(f); });

  return (
    <div className="space-y-4">
      {orderedFields.filter(isFieldVisible).map(field => (
        <FieldRenderer
          key={field.id}
          field={field}
          value={values[String(field.id)]}
          onChange={onChange}
          errors={errors}
        />
      ))}
    </div>
  );
}
