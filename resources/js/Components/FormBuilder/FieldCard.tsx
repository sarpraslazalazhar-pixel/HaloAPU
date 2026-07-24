import React from 'react';
import { GripVertical, Edit, Trash } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { FormField } from '@/types';

interface FieldCardProps {
 field: FormField;
 onEdit: (field: FormField) => void;
 onDelete: (id: number) => void;
 dragHandleProps?: any;
}

export default function FieldCard({ field, onEdit, onDelete, dragHandleProps }: FieldCardProps) {
 return (
 <div className="flex items-center gap-4 bg-white border p-4 mb-2 rounded-lg shadow-sm">
 <div {...dragHandleProps} className="cursor-grab text-gray-400 hover:text-gray-600">
 <GripVertical size={20} />
 </div>
 <div className="flex-1">
 <div className="flex items-center gap-2">
 <span className="font-medium text-gray-800">{field.label}</span>
 {field.wajib && (
 <span className="bg-red-100 text-red-700 text-xs px-2 py-0.5 rounded-full font-medium">Wajib</span>
 )}
 </div>
 <div className="text-sm text-gray-500 mt-1">
 <span className="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs border border-blue-200">
 {field.tipe_field.replace('_', ' ')}
 </span>
 </div>
 </div>
 <div className="flex gap-2">
 <Button variant="outline" size="icon" onClick={() => onEdit(field)}>
 <Edit size={16} />
 </Button>
 <Button variant="outline" size="icon" className="text-red-600 hover:text-red-700 hover:bg-red-50" onClick={() => onDelete(field.id)}>
 <Trash size={16} />
 </Button>
 </div>
 </div>
 );
}
