import React, { useEffect } from 'react';
import { FormField } from '@/types';
import FieldRenderer from '@/Components/FormBuilder/FieldRenderer';

interface DynamicFieldProps {
 fields: FormField[];
 values: Record<string, any>;
 onChange: (fieldId: number, value: any) => void;
 errors?: Record<string, string>;
}

export default function DynamicField({ fields, values, onChange, errors }: DynamicFieldProps) {
 const visibleFields = fields.filter(field => {
 if (!field.parent_field_id) return true;
 const parentValue = values[field.parent_field_id];
 return parentValue === field.trigger_value;
 });

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
