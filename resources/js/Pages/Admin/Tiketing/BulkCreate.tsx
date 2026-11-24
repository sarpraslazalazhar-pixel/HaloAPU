import React from 'react';
import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { BulkTicketBoard, type BulkUnitOption } from '@/Components/Tickets/BulkTicketBoard';

interface BulkCreateProps {
  unitList: BulkUnitOption[];
  maxTickets: number;
  priorities: string[];
}

export default function BulkCreate({ unitList, maxTickets, priorities }: BulkCreateProps) {
  return (
    <AdminLayout title="Ajukan Banyak Tiket">
      <Head title="Ajukan Banyak Tiket" />
      <BulkTicketBoard
        unitList={unitList}
        maxTickets={maxTickets}
        priorities={priorities}
        showPriority
        submitUrl={route('admin.tickets.bulk.store')}
        backUrl={route('admin.tiket.index')}
      />
    </AdminLayout>
  );
}
