import React from 'react';
import { FileText, Download, Eye } from 'lucide-react';
import { Button } from './ui/button';

import { AttachmentViewer } from './AttachmentViewer';

interface Attachment {
    id: number;
    original_name: string;
    file_path: string;
    field_id?: number | null;
}

interface TicketAttachmentListProps {
    attachments: Attachment[];
    downloadRoute: string;
}

export function TicketAttachmentList({ attachments, downloadRoute }: TicketAttachmentListProps) {
    if (!attachments?.length) return null;

    const viewRoute = downloadRoute.replace('.download', '.view');

    return (
        <div className="space-y-2">
            {attachments.map((att) => (
                <div key={att.id} className="flex items-center justify-between p-3 border rounded-lg bg-white">
                    <div className="flex items-center gap-3">
                        <FileText className="h-4 w-4 text-slate-400" />
                        <span className="text-sm font-medium">{att.original_name || att.file_path?.split('/').pop()}</span>
                    </div>
                    
                    <AttachmentViewer attachment={att} viewRoute={viewRoute} downloadRoute={downloadRoute}>
                        <Button type="button" variant="ghost" size="sm" className="text-blue-600 hover:text-blue-800 flex items-center gap-1 hover:bg-blue-50">
                            <FileText className="h-4 w-4" />
                            Lihat File
                        </Button>
                    </AttachmentViewer>
                </div>
            ))}
        </div>
    );
}
