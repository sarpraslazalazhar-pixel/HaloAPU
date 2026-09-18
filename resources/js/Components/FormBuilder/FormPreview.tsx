import React from 'react';
import { FormField } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

export type FormPreviewItem = { name?: string };

export type FormPreviewValue = string | number | boolean | FormPreviewItem | null | undefined;

interface FormPreviewProps {
  fields: FormField[];
  values: Record<string, FormPreviewValue>;
}

export default function FormPreview({ fields, values }: FormPreviewProps) {
  if (fields.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Pratinjau Form</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {fields.map(field => {
          const val = values[field.id];
          let displayVal = '-';

          if (field.tipe_field === 'checkbox') {
            displayVal = val ? 'Ya' : 'Tidak';
          } else if (field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file') {
            // SAFETY: Upload fields store a File or object with a name property.
            displayVal = (val as FormPreviewItem | undefined)?.name || '-';
          } else if (val != null) {
            displayVal = String(val);
          }

          return (
            <div key={field.id}>
              <span className="text-sm font-medium">{field.label}{field.wajib && ' *'}</span>
              <p className="text-sm text-muted-foreground mt-0.5">{displayVal}</p>
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}
