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

export default function DynamicField({ fields, values, onChange, errors }: DynamicFieldProps) {
 const isFieldVisible = (field: FormField) => {
   if (!field.parent_field_id) return true;
   const parentValue = values[field.parent_field_id];
   if (parentValue === undefined || parentValue === null) return false;

   if (Array.isArray(parentValue)) {
     return parentValue.includes(field.trigger_value as string);
   }

   return parentValue === field.trigger_value;
 };

 // Urutkan field agar cabang/child langsung berada tepat di bawah parent-nya
 const orderedFields: FormField[] = [];
 const rootFields = fields.filter(f => !f.parent_field_id);
 const childMap = new Map<number, FormField[]>();

 fields.forEach(f => {
   if (f.parent_field_id) {
     if (!childMap.has(f.parent_field_id)) {
       childMap.set(f.parent_field_id, []);
     }
     childMap.get(f.parent_field_id)!.push(f);
   }
 });

 const appendFieldAndChildren = (field: FormField) => {
   orderedFields.push(field);
   const children = childMap.get(field.id) || [];
   children.forEach(child => appendFieldAndChildren(child));
 };

 rootFields.forEach(root => appendFieldAndChildren(root));

 const addedIds = new Set(orderedFields.map(f => f.id));
 fields.forEach(f => {
   if (!addedIds.has(f.id)) {
     orderedFields.push(f);
   }
 });

 const visibleFields = orderedFields.filter(isFieldVisible);

 return (
 <div className="space-y-4">
 {visibleFields.map(field => (
 <FieldRenderer
 key={field.id}
 field={field}
 value={values[field.id]}
 onChange={onChange}
 errors={errors}
 />
 ))}
 </div>
 );
}
