import React from 'react';
import { Head } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { BulkTicketBoard, type BulkUnitOption } from '@/Components/Tickets/BulkTicketBoard';

interface UserBulkCreateProps {
  unitList: BulkUnitOption[];
  maxTickets: number;
}

export default function BulkCreate({ unitList, maxTickets }: UserBulkCreateProps) {
  return (
    <UserLayout title="Ajukan Banyak Tiket">
      <Head title="Ajukan Banyak Tiket" />
      <BulkTicketBoard
        unitList={unitList}
        maxTickets={maxTickets}
        showPriority={false}
        submitUrl={route('tiket.bulk.store')}
        backUrl={route('tiket.riwayat')}
        singleCreateUrl={route('tiket.create')}
        singleCreateLabel="Buat Tiket Satuan"
      />
    </UserLayout>
  );
}
