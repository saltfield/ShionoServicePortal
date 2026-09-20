<script>
document.addEventListener('DOMContentLoaded', () => {
    const lookupUrl = @json(url('/postal-lookup'));

    const formatPostal = (value) => {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 7);
        if (digits.length <= 3) {
            return digits;
        }
        return `${digits.slice(0, 3)}-${digits.slice(3)}`;
    };

    const restrictPostal = (event) => {
        const input = event.target;
        const start = input.selectionStart;
        const before = input.value;
        input.value = formatPostal(before.replace(/[^\d-]/g, ''));
        if (document.activeElement === input && typeof start === 'number') {
            input.setSelectionRange(input.value.length, input.value.length);
        }
    };

    const restrictPhone = (event) => {
        const input = event.target;
        input.value = String(input.value || '').replace(/[^0-9-]/g, '').slice(0, 20);
    };

    const lookupAddress = async (postalInput) => {
        const addressId = postalInput.dataset.addressTarget;
        const addressInput = addressId ? document.getElementById(addressId) : null;
        const zip = String(postalInput.value || '').replace(/\D/g, '');

        if (zip.length !== 7) {
            return;
        }

        postalInput.value = formatPostal(zip);

        try {
            const response = await fetch(`${lookupUrl}?zipcode=${encodeURIComponent(zip)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                alert(data.message || '住所の取得に失敗しました。');
                return;
            }

            postalInput.value = data.postal_code;
            if (addressInput) {
                addressInput.value = data.address;
                addressInput.focus();
            }
        } catch (error) {
            alert('住所の取得に失敗しました。');
        }
    };

    document.querySelectorAll('.js-postal-code').forEach((input) => {
        input.addEventListener('input', restrictPostal);
        input.addEventListener('blur', () => {
            input.value = formatPostal(input.value);
            if (String(input.value).replace(/\D/g, '').length === 7) {
                lookupAddress(input);
            }
        });
    });

    document.querySelectorAll('.js-phone').forEach((input) => {
        input.addEventListener('input', restrictPhone);
    });

    document.querySelectorAll('.js-postal-lookup').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.postalInput);
            if (input) {
                lookupAddress(input);
            }
        });
    });
});
</script>
