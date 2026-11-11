import React from 'react';
import { router } from '@inertiajs/react';

export default function ViewReceiptFormModal({ open, onClose, product, onDeleted }) {
if (!open || !product) return null;

const receiptUrl = product.receipt_form_url
    || (product.receipt_form ? `/storage/${product.receipt_form}` : null);

const handlePrepare = (e) => {
    e.preventDefault();
    router.delete(`/admin/products/${product.product_id}`, {
        onSuccess: () => {
            if (onDeleted) onDeleted();
            onClose();
        },
        preserveScroll: true,
        replace: true,
    });
};

return (
    <div
        className='fixed inset-0 z-[60] flex justify-center items-center backdrop-blur-xs bg-black/5'
        onClick={onClose}
    >
        <div
            className="bg-[#F6F6F6] shadow-lg relative w-120 h-100 rounded-2xl"
            onClick={e => e.stopPropagation()}
        >
            <div className='flex flex-col p-10 items-center justify-center'>
                {receiptUrl && (
                    <img src={receiptUrl} alt="Receipt Form" className="max-w-full max-h-64 mb-5" />
                )}
                <div className='flex flex-row gap-3 mt-5'>
                    <button type='button' className='flex justify-center items-center bg-white text-[#9C0306] border border-[#9C0306] text-[16px] font-semibold w-30 h-10 rounded-[5px] hover:cursor-pointer' onClick={(e) => { e.stopPropagation(); onClose(); }}>
                        Back
                    </button>
                    {receiptUrl && (
                        <a href={receiptUrl} download className='flex justify-center items-center bg-[#9C0306] text-white text-[16px] font-semibold border border-[#9C0306] w-30 h-10 rounded-[5px] hover:cursor-pointer'>
                            Download
                        </a>
                    )}
                </div>
            </div>
        </div>
    </div>
);
}
                    