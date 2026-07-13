import React from 'react';
import { FormField } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

interface FormPreviewProps {
    fields: FormField[];
    values: Record<string, any>;
}

export default function FormPreview({ fields, values }: FormPreviewProps) {
    if (fields.length === 0) return null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Pratinjau Form</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {fields.map(field => (
                    <div key={field.id}>
                        <span className="text-sm font-medium">{field.label}{field.wajib && ' *'}</span>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            {field.tipe_field === 'checkbox'
                                ? (values[field.id] ? 'Ya' : 'Tidak')
                                : field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file'
                                    ? (values[field.id]?.name || '-')
                                    : values[field.id] || '-'}
                        </p>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
