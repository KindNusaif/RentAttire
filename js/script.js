
/* ==========================================================
   API HELPERS (used by every page)
   ========================================================== */
const API = (window.location.pathname.includes("/admin/") ? "../" : "") + "backend/api/index.php";
let csrfToken = "";

async function api(action, options = {}, query = "") {
    const config = { credentials: "same-origin", headers: {} };
    if (options.method) config.method = options.method;
    const formData = typeof FormData !== "undefined" && options.body instanceof FormData;
    if (options.body) {
        config.method = config.method || "POST";
        if (formData) {
            config.body = options.body;
        } else {
            config.headers["Content-Type"] = "application/json";
            config.body = JSON.stringify(options.body);
        }
    }
    if (["POST", "PUT", "PATCH", "DELETE"].includes(config.method) && !["register", "vendor-register", "login"].includes(action)) {
        if (!csrfToken) {
            const tokenResponse = await fetch(API + "?action=csrf", { credentials: "same-origin" });
            csrfToken = tokenResponse.headers.get("X-CSRF-Token") || "";
        }
        config.headers["X-CSRF-Token"] = csrfToken;
    }
    const response = await fetch(API + "?action=" + action + query, config);
    csrfToken = response.headers.get("X-CSRF-Token") || csrfToken;
    return response.json();
}

function escapeHtml(text) {
    return String(text ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

function orderCode(orderId) {
    return "RA" + (1000 + Number(orderId));
}

const STATUS_INFO = {
    pending:          { label: "Pending",          badge: "badge-limited" },
    confirmed:        { label: "Confirmed",        badge: "badge-available" },
    processing:       { label: "Preparing",        badge: "badge-limited" },
    ready_for_pickup: { label: "Ready for Pickup", badge: "badge-available" },
    picked_up:        { label: "Picked Up",        badge: "badge-limited" },
    returned:         { label: "Returned",         badge: "badge-available" },
    cancelled:        { label: "Cancelled",        badge: "badge-unavailable" }
};

function statusBadge(status) {
    const info = STATUS_INFO[status] || { label: status, badge: "badge-limited" };
    return '<span class="badge ' + info.badge + '">' + escapeHtml(info.label) + '</span>';
}

function mapProduct(p) {
    const stock = Number(p.stock);
    const images = Array.isArray(p.images) && p.images.length ? p.images : [p.image_path || "images/logo.svg"];
    const sizes = Array.isArray(p.sizes) ? p.sizes : (p.sizes || "Free Size").split(",");
    return {
        id: p.product_id,
        name: p.product_name,
        category: p.category_name || "",
        categoryId: p.category_id,
        price: Number(p.price_per_day),
        deposit: Number(p.deposit_amount),
        stock: stock,
        availability: stock === 0 ? "Unavailable" : (stock <= 2 ? "Limited" : "Available"),
        description: p.description || "",
        sizes: sizes.map(s => String(s).trim()).filter(Boolean),
        variants: p.variants || [],
        photos: images,
        image: images[0] || "images/logo.svg",
        shopId: p.shop_id,
        shopName: p.shop_name || "Rent Attire Collection",
        sizeTypeId: p.size_type_id || null,
        sizeTypeCode: p.size_type_code || null,
        sizeTypeName: p.size_type_name || null,
        categoryGroup: p.category_group || null,
        customNotes: p.custom_measurement_notes || "",
        customMeasurementNotes: p.custom_measurement_notes || "",
        status: p.status
    };
}

async function loadProducts() {
    const result = await api("products");
    if (!result.success) throw new Error(result.message || "Could not load products.");
    return result.products.map(mapProduct);
}

async function updateHeader() {
    const link = document.querySelector('.nav-icons a[href="login.html"]');
    if (!link) return;
    try {
        const result = await api("me");
        if (!result.user) return;
        const role = result.user.role;
        link.textContent = "My Account";
        link.href = role === "admin" ? "admin/dashboard.html" : role === "vendor" ? "vendor-dashboard.html" : "account.html";
        const out = document.createElement("a");
        out.href = "#";
        out.textContent = "Sign out";
        out.setAttribute("data-signout", "");
        link.after(out);
    } catch (error) { /* ignore */ }
}

function wireSignOut() {
    document.addEventListener("click", async (event) => {
        const target = event.target.closest("[data-signout]");
        if (!target) return;
        event.preventDefault();
        try { await api("logout", { method: "POST" }); } catch (error) { /* ignore */ }
        window.location.href = "index.html";
    });
}





/* ----------------------------------------------------------
   1) MOBILE MENU TOGGLE
   ---------------------------------------------------------- */
function toggleMenu() {
    const navLinks = document.getElementById("navLinks");
    const hamburger = document.getElementById("hamburger");
    if (!navLinks || !hamburger) return;

    const isOpen = navLinks.classList.toggle("open");
    navLinks.classList.toggle("active", isOpen);
    hamburger.setAttribute("aria-expanded", String(isOpen));
    document.body.classList.toggle("menu-open", isOpen);
}


/*
   2) CART COUNT BADGE (top right of the header)
 */
async function updateCartCount() {
    const countEl = document.getElementById("cartCount");
    if (!countEl) return;
    try {
        const result = await api("cart");
        countEl.textContent = result.success
            ? result.items.reduce((sum, item) => sum + Number(item.quantity), 0)
            : 0;
    } catch (error) {
        countEl.textContent = 0;
    }
}


/* 
   3) ADD TO CART
   Called from product-details.html once the dates have been
   validated and the rental price has been calculated.*/
async function addToCart(product, qty, days, pickupDate, returnDate, variantId = null) {
    try {
        const session = await api("me");
        if (!session.user) {
            alert("Please sign in to rent an item.");
            window.location.href = "login.html";
            return;
        }
        const result = await api("cart", {
            body: {
                product_id: product.id,
                variant_id: variantId || product.variantId || null,
                quantity: qty,
                pickup_date: pickupDate,
                return_date: returnDate,
                size: product.size || ""
            }
        });
        if (!result.success) {
            alert(result.message);
            return;
        }
        alert(product.name + " has been added to your cart!");
        window.location.href = "cart.html";
    } catch (error) {
        alert("Could not reach the server. Is WAMP running?");
    }
}


/*
   4) REMOVE FROM CART
   (cart.html also defines its own copy of this to re-render
   the page immediately - this version is kept for any other
   page that might need it.)
    */



/* 
   5) RENTAL PRICE CALCULATOR (product-details.html)
   Reads the Pickup Date and Return Date fields, works out the
   number of days, and shows: days x price/day = total.
   Returns { days } so handleAddToCart() can check it passed.
   ---------------------------------------------------------- */
function calculateRentalPrice() {
    const pickupInput = document.getElementById("pickupDate");
    const returnInput = document.getElementById("returnDate");
    const display = document.getElementById("totalPriceDisplay");
    if (!pickupInput || !returnInput || !display) return null;

    const pricePerDay = window.currentProduct ? window.currentProduct.price : 2500;

    if (!pickupInput.value || !returnInput.value) {
        display.textContent = "Rs. 0";
        return null;
    }

    const pickup = new Date(pickupInput.value);
    const ret = new Date(returnInput.value);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    // Rule 1: pickup date cannot be in the past
    if (pickup < today) {
        alert("Pickup date cannot be in the past.");
        display.textContent = "Rs. 0";
        return null;
    }

    // Rule 2: return date must be after pickup date
    if (ret <= pickup) {
        alert("Return date must be after the pickup date.");
        display.textContent = "Rs. 0";
        return null;
    }

    const days = Math.round((ret - pickup) / (1000 * 60 * 60 * 24));
    const total = days * pricePerDay;

    display.textContent = "Rs. " + total.toLocaleString() + " (" + days + " day" + (days > 1 ? "s" : "") + ")";

    return { days: days, total: total };
}


/* 
   6) LOGIN FORM VALIDATION
   */
async function validateLoginForm(event) {
    event.preventDefault();

    const email = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value.trim();

    if (email === "") {
        alert("Please enter your email.");
        return false;
    }

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email)) {
        alert("Please enter a valid email address.");
        return false;
    }

    if (password === "") {
        alert("Please enter your password.");
        return false;
    }

    try {
        const result = await api("login", { body: { email: email, password: password } });
        if (!result.success) {
            alert(result.message);
            return false;
        }
        if (result.user.role === "admin") {
            window.location.href = "admin/dashboard.html";
        } else if (result.user.role === "vendor") {
            window.location.href = "vendor-dashboard.html";
        } else {
            window.location.href = "account.html";
        }
    } catch (error) {
        alert("Could not reach the server. Is WAMP running?");
    }
    return false;
}


/* 
   7) REGISTRATION FORM VALIDATION
    */
async function validateRegisterForm(event) {
    event.preventDefault();

    const fname = document.getElementById("fname").value.trim();
    const lname = document.getElementById("lname").value.trim();
    const email = document.getElementById("email").value.trim();
    const phone = document.getElementById("phone").value.trim();
    const password = document.getElementById("password").value;
    const cpassword = document.getElementById("cpassword").value;
    const terms = document.getElementById("terms").checked;

    if (fname === "" || lname === "") {
        alert("Please enter your first and last name.");
        return false;
    }

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email)) {
        alert("Please enter a valid email address.");
        return false;
    }

    const phonePattern = /^[0-9]{9,10}$/;
    if (!phonePattern.test(phone)) {
        alert("Please enter a valid phone number (9-10 digits).");
        return false;
    }

    if (password.length < 8) {
        alert("Password must be at least 8 characters long.");
        return false;
    }

    if (password !== cpassword) {
        alert("Passwords do not match.");
        return false;
    }

    if (!terms) {
        alert("You must agree to the Terms & Conditions.");
        return false;
    }

    try {
        const result = await api("register", {
            body: { first_name: fname, last_name: lname, email: email, phone: phone, password: password }
        });
        if (!result.success) {
            alert(result.message);
            return false;
        }
        alert("Account created successfully!");
        window.location.href = "account.html";
    } catch (error) {
        alert("Could not reach the server. Is WAMP running?");
    }
    return false;
}


/* 
   8) CONTACT FORM VALIDATION
    */
async function validateContactForm(event) {
    event.preventDefault();

    const name = document.getElementById("name").value.trim();
    const email = document.getElementById("email").value.trim();
    const subject = document.getElementById("subject").value.trim();
    const message = document.getElementById("message").value.trim();

    if (name === "" || subject === "" || message === "") {
        alert("Please fill in all fields.");
        return false;
    }

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email)) {
        alert("Please enter a valid email address.");
        return false;
    }

    try {
        const result = await api("contact", {
            body: { name: name, email: email, subject: subject, message: message }
        });
        if (!result.success) {
            alert(result.message);
            return false;
        }
        alert("Message sent! We'll get back to you soon.");
        document.getElementById("contactForm").reset();
    } catch (error) {
        alert("Could not reach the server. Is WAMP running?");
    }
    return false;
}


/* ----------------------------------------------------------
   9) ORDER TRACKING (tracking.html)
   Mock lookup that stands in for a future Ajax call to
   php/track-order.php. Highlights the current step on the
   timeline based on the order's status.
   ---------------------------------------------------------- */


const trackingLabels = ["Booking Placed", "Confirmed", "Preparing", "Ready for Pickup", "Picked Up", "Returned"];
const trackingIndex = { pending: 0, confirmed: 1, processing: 2, ready_for_pickup: 3, picked_up: 4, returned: 5 };

function renderTimeline(order) {
    const items = (order.items || []).map(i => escapeHtml(i.product_name) + (i.size ? " (" + escapeHtml(i.size) + ")" : "")).join(", ");
    let html = '<h4 class="mb-3">' + orderCode(order.order_id) + ' &middot; ' + items + '</h4>';
    if (order.status === "cancelled") {
        return html + '<p>' + statusBadge("cancelled") + ' This order was cancelled.</p>';
    }
    const current = trackingIndex[order.status] ?? 0;
    html += '<div class="timeline">';
    trackingLabels.forEach(function (label, i) {
        let stateClass = "";
        if (i < current) stateClass = "done";
        if (i === current) stateClass = "current";
        html += '<div class="timeline-step ' + stateClass + '">' + label + '</div>';
    });
    return html + '</div>';
}

async function trackOrder(event) {
    if (event) event.preventDefault();
    const resultBox = document.getElementById("trackingResult");
    const typed = document.getElementById("orderId").value.trim().toUpperCase().replace("RA", "");
    try {
        const result = await api("my-orders");
        if (!result.success) {
            resultBox.innerHTML = '<p class="text-muted">Please <a href="login.html" style="color:var(--primary);">sign in</a> to track your orders.</p>';
            return false;
        }
        const order = result.orders.find(o => orderCode(o.order_id) === "RA" + typed);
        if (!order) {
            resultBox.innerHTML = '<p class="text-muted">No order found with that ID in your account.</p>';
            return false;
        }
        resultBox.innerHTML = renderTimeline(order);
    } catch (error) {
        resultBox.innerHTML = '<p class="text-muted">Could not reach the server.</p>';
    }
    return false;
}

async function initTrackingPage() {
    const resultBox = document.getElementById("trackingResult");
    if (!resultBox || !document.getElementById("orderId")) return;
    try {
        const result = await api("my-orders");
        if (!result.success) {
            resultBox.innerHTML = '<p class="text-muted">Please <a href="login.html" style="color:var(--primary);">sign in</a> to track your orders.</p>';
            return;
        }
        if (result.orders.length === 0) {
            resultBox.innerHTML = '<p class="text-muted">You have no orders yet.</p>';
            return;
        }
        resultBox.innerHTML = renderTimeline(result.orders[0]);
    } catch (error) { /* ignore */ }
}

async function renderHistory() {
    const body = document.getElementById("historyBody");
    if (!body) return;
    try {
        const result = await api("my-orders");
        if (!result.success) {
            body.innerHTML = '<tr><td colspan="5">Please <a href="login.html" style="color:var(--primary);">sign in</a> to see your orders.</td></tr>';
            return;
        }
        if (result.orders.length === 0) {
            body.innerHTML = '<tr><td colspan="5">You have not rented anything yet. <a href="products.html" style="color:var(--primary);">Browse the collection</a>.</td></tr>';
            return;
        }
        body.innerHTML = result.orders.map(order => {
            const names = order.items.map(i => escapeHtml(i.product_name) + (i.size ? " (" + escapeHtml(i.size) + ")" : "")).join(", ");
            const first = order.items[0];
            const dates = first ? first.pickup_date + " to " + first.return_date : "-";
            return "<tr><td>" + orderCode(order.order_id) + "</td><td>" + names + "</td><td>" + dates +
                "</td><td>Rs. " + Number(order.grand_total).toLocaleString() + "</td><td>" + statusBadge(order.status) + "</td></tr>";
        }).join("");
    } catch (error) {
        body.innerHTML = '<tr><td colspan="5">Could not reach the server.</td></tr>';
    }
}

async function renderAccount() {
    const nameEl = document.getElementById("profileName");
    if (!nameEl) return;
    try {
        const result = await api("profile");
        if (!result.success) {
            window.location.href = "login.html";
            return;
        }
        const p = result.profile;
        nameEl.textContent = p.first_name + " " + p.last_name;
        document.getElementById("profileEmail").textContent = p.email;
        document.getElementById("profilePhone").textContent = p.phone || "-";
    } catch (error) { /* ignore */ }
}


/* ----------------------------------------------------------
   10) VENDOR DATA & LOGIC (Marketplace Expansion)
   ---------------------------------------------------------- */
const shops = [
    {
        id: 1,
        name: "Colombo Silk House",
        city: "Colombo",
        category: "Sarees, Bridal Wear",
        rating: 4.7,
        logo: "images/logo.svg"
    },
    {
        id: 2,
        name: "Kandy Formal Wear",
        city: "Kandy",
        category: "Suits, Formal",
        rating: 4.5,
        logo: "images/logo.svg"
    }
];

const mockProducts = [
    {
        id: 1,
        name: "Royal Red Saree",
        category: "Sarees",
        price: 2500,
        availability: "Available",
        description: "A premium traditional saree with intricate gold details, perfect for weddings, cultural celebrations, and formal occasions.",
        sizes: ["XS", "S", "M", "L", "XL"],
        photos: ["images/saree_royal_red.png", "images/saree_model.png"],
        shopId: 1
    },
    {
        id: 2,
        name: "Classic Black Suit",
        category: "Suits",
        price: 3000,
        availability: "Available",
        description: "A sharp black formal suit tailored for weddings, business events, and evening celebrations.",
        sizes: ["S", "M", "L", "XL", "2XL"],
        photos: ["images/suit_classic_black.png", "images/suit_model.png"],
        shopId: 2
    },
    {
        id: 3,
        name: "Pearl Bridal Dress",
        category: "Bridal Wear",
        price: 5000,
        availability: "Limited",
        description: "An elegant bridal design with pearl-inspired details for a memorable wedding celebration.",
        sizes: ["XS", "S", "M", "L", "XL"],
        photos: ["images/bridal_model.png", "images/evening_dress.png"],
        shopId: 1
    },
    {
        id: 4,
        name: "Emerald Evening Dress",
        category: "Dresses",
        price: 3500,
        availability: "Available",
        description: "A refined emerald evening dress designed for galas, parties, and special nights out.",
        sizes: ["XS", "S", "M", "L", "XL"],
        photos: ["images/evening_dress.png", "images/bridal_model.png"],
        shopId: 1
    },
    {
        id: 5,
        name: "Traditional Gold Saree",
        category: "Sarees",
        price: 2800,
        availability: "Available",
        description: "A graceful gold-toned saree inspired by traditional South Asian craftsmanship and celebration wear.",
        sizes: ["XS", "S", "M", "L", "XL"],
        photos: ["images/saree_model.png", "images/saree_royal_red.png"],
        shopId: 1
    },
    {
        id: 6,
        name: "Midnight Blue Suit",
        category: "Suits",
        price: 3200,
        availability: "Unavailable",
        description: "A polished midnight blue suit with a classic silhouette for formal occasions and evening events.",
        sizes: ["S", "M", "L", "XL", "2XL"],
        photos: ["images/suit_model.png", "images/suit_classic_black.png"],
        shopId: 2
    },
    {
        id: 7,
        name: "Ivory Bridal Gown",
        category: "Bridal Wear",
        price: 5500,
        availability: "Available",
        description: "A soft ivory bridal gown with a graceful finish for ceremonies, receptions, and formal portraits.",
        sizes: ["XS", "S", "M", "L", "XL"],
        photos: ["images/evory.jpg", "images/bridal_model.png"],
        shopId: 1
    },
    {
        id: 8,
        name: "Rose Party Dress",
        category: "Dresses",
        price: 2900,
        availability: "Limited",
        description: "A romantic rose party dress made for celebrations, dinners, and elegant social occasions.",
        sizes: ["XS", "S", "M", "L", "XL"],
        photos: ["images/evening_dress.png", "images/bridal_model.png"],
        shopId: 1
    }
];

mockProducts.forEach(product => {
    product.image = product.photos[0];
});

function getProductFromUrl() {
    const id = new URLSearchParams(window.location.search).get("id");
    return mockProducts.find(product => String(product.id) === String(id));
}

async function renderProductDetails() {
    const detailLayout = document.getElementById("productDetailLayout");
    const notFound = document.getElementById("productNotFound");
    if (!detailLayout || !notFound) return;

    const productId = new URLSearchParams(window.location.search).get("id");
    let products;
    try {
        products = await loadProducts();
    } catch (error) {
        detailLayout.style.display = "none";
        notFound.style.display = "block";
        notFound.textContent = error.message;
        return;
    }
    const product = products.find(item => String(item.id) === String(productId));
    if (!product) {
        detailLayout.style.display = "none";
        notFound.style.display = "block";
        return;
    }

    const shop = { id: product.shopId, name: product.shopName };
    const mainImage = document.getElementById("mainImage");
    const gallery = document.getElementById("productGallery");
    const badge = document.getElementById("productAvailability");

    detailLayout.style.display = "grid";
    notFound.style.display = "none";
    document.title = product.name + " | Rent Attire";
    document.getElementById("productCategory").textContent = product.category;
    document.getElementById("productName").textContent = product.name;
    document.getElementById("productDescription").textContent = product.description;
    document.getElementById("productPrice").textContent = "Rs. " + product.price.toLocaleString() + " / day";
    const shopLink = document.getElementById("productShop");
    shopLink.textContent = shop ? shop.name : "Rent Attire Collection";
    shopLink.href = shop ? "shop-profile.html?id=" + shop.id : "shops.html";

    badge.textContent = product.availability;
    badge.className = "badge mb-3 " + (product.availability === "Available" ? "badge-available" : product.availability === "Limited" ? "badge-limited" : "badge-unavailable");

    mainImage.src = product.photos[0];
    mainImage.alt = product.name;
    gallery.innerHTML = product.photos.map((photo, index) => `
        <img src="${escapeHtml(photo)}" alt="${escapeHtml(product.name)} photo ${index + 1}" data-photo="${escapeHtml(photo)}" class="product-thumbnail${index === 0 ? " active" : ""}">
    `).join("");
    gallery.querySelectorAll(".product-thumbnail").forEach(thumbnail => {
        thumbnail.addEventListener("click", () => selectProductPhoto(thumbnail.dataset.photo, thumbnail));
    });

    window.currentProduct = product;
    if (typeof populateProductSizes === "function") populateProductSizes(product.sizes);
}

function selectProductPhoto(photo, thumbnail) {
    document.getElementById("mainImage").src = photo;
    document.querySelectorAll(".product-thumbnail").forEach(item => item.classList.remove("active"));
    thumbnail.classList.add("active");
}

function wireProductCards() {
    document.querySelectorAll("[data-product-id]").forEach(card => {
        const productId = card.dataset.productId;
        const detailsUrl = "product-details.html?id=" + encodeURIComponent(productId);

        card.querySelectorAll('a[href^="product-details.html"]').forEach(link => {
            link.href = detailsUrl;
        });

        card.setAttribute("role", "link");
        card.setAttribute("tabindex", "0");
        card.addEventListener("click", event => {
            if (event.target.closest("a, button, input, select")) return;
            window.location.href = detailsUrl;
        });
        card.addEventListener("keydown", event => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                window.location.href = detailsUrl;
            }
        });
    });
}

function validateVendorRegisterForm(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const body = {
        shop_name: document.getElementById("shopName").value.trim(),
        owner_name: document.getElementById("ownerName").value.trim(),
        email: document.getElementById("email").value.trim(),
        phone: document.getElementById("phone")?.value.trim() || "",
        shop_email: document.getElementById("shopEmail")?.value.trim() || "",
        address: document.getElementById("address")?.value.trim() || "",
        description: document.getElementById("description")?.value.trim() || "",
        password: document.getElementById("password").value
    };
    api("vendor-register", { body }).then(result => {
        setPageMessage(form, result.message || "Vendor application submitted.", !result.success);
        if (result.success) form.reset();
    }).catch(() => setPageMessage(form, "Could not reach the server. Check that WAMP is running.", true));
    return false;
}

function validateVendorLoginForm(event) {
    event.preventDefault();
    const form = event.currentTarget;
    api("login", { body: {
        email: document.getElementById("email").value.trim(),
        password: document.getElementById("password").value
    }}).then(async result => {
        if (!result.success) {
            setPageMessage(form, result.message, true);
            return;
        }
        if (result.user.role !== "vendor") {
            await api("logout", { method: "POST" });
            setPageMessage(form, "This portal is for vendors only.", true);
            return;
        }
        window.location.href = "vendor-dashboard.html";
    }).catch(() => setPageMessage(form, "Could not reach the server. Check that WAMP is running.", true));
    return false;
}

function setPageMessage(container, message, isError = false) {
    let node = container.querySelector("[data-form-message]");
    if (!node) {
        node = document.createElement("p");
        node.dataset.formMessage = "";
        node.setAttribute("role", "status");
        container.appendChild(node);
    }
    node.textContent = message;
    node.style.color = isError ? "var(--error)" : "var(--success)";
}

async function loadVendorProducts() {
    const result = await api("products", {}, "&manage=1");
    if (!result.success) throw new Error(result.message || "Could not load products.");
    return result.products.map(mapProduct);
}

async function renderVendorDashboard() {
    const heading = document.getElementById("vendorWelcome");
    if (!heading) return;
    const [session, result] = await Promise.all([api("me"), api("vendor-dashboard")]);
    if (!session.user || session.user.role !== "vendor") {
        window.location.href = "vendor-login.html";
        return;
    }
    if (!result.success) {
        setPageMessage(document.querySelector(".container"), result.message || "Could not load the dashboard.", true);
        return;
    }
    const dashboard = result.dashboard;
    heading.textContent = `Welcome back, ${session.user.name}`;
    document.getElementById("vendorEarnings").textContent = "Rs. " + Number(dashboard.earnings).toLocaleString();
    document.getElementById("vendorOrdersCount").textContent = dashboard.total_orders;
    document.getElementById("vendorProductsCount").textContent = dashboard.total_products;
    document.getElementById("vendorPendingCount").textContent = dashboard.pending_requests;
    document.getElementById("vendorActiveCount").textContent = dashboard.active_rentals;
    const approvalNotice = document.getElementById("vendorApprovalNotice");
    if (approvalNotice) {
        const pending = dashboard.vendor_status !== "active" || result.shops.some(shop => shop.status !== "approved");
        approvalNotice.hidden = !pending;
        approvalNotice.textContent = dashboard.vendor_status === "suspended"
            ? "Your vendor account is suspended. Contact an administrator for assistance."
            : "Your shop is awaiting admin approval. You can set up products, but they will not be published until approval.";
    }
    const recent = document.getElementById("vendorRecentOrders");
    if (recent) {
        recent.innerHTML = result.recent_orders.length ? result.recent_orders.map(order => `
            <tr><td>${escapeHtml(orderCode(order.order_id))}</td><td>${escapeHtml(order.product_name)}</td>
            <td>${escapeHtml(order.first_name)} ${escapeHtml(order.last_name)}</td>
            <td>${escapeHtml(order.pickup_date)} – ${escapeHtml(order.return_date)}</td>
            <td>${statusBadge(order.vendor_status)}</td></tr>`).join("") : '<tr><td colspan="5">No rental requests yet.</td></tr>';
    }
}

async function renderVendorProducts() {
    const container = document.getElementById("vendorProductsGrid");
    if (!container) return;
    try {
        const products = await loadVendorProducts();
        container.innerHTML = products.length ? products.map(product => `
            <div class="card">
                <div class="card-img"><img src="${escapeHtml(product.image)}" alt="${escapeHtml(product.name)}"></div>
                <div class="card-body">
                    ${statusBadge(product.status)}
                    <p class="text-muted">${escapeHtml(product.shopName)} · ${escapeHtml(product.category)}</p>
                    <h3 class="card-title">${escapeHtml(product.name)}</h3>
                    <div class="price">Rs. ${product.price.toLocaleString()} / day</div>
                    <p class="text-muted">Stock: ${product.stock} · Sizes: ${escapeHtml(product.sizes.join(", ") || "Not set")}</p>
                    <div class="d-flex" style="gap:10px;">
                        <a class="btn btn-outline" style="flex:1;" href="vendor-add-product.html?id=${encodeURIComponent(product.id)}">Edit</a>
                        <button class="btn btn-outline" style="flex:1; border-color:var(--error); color:var(--error);" onclick="deleteVendorProduct(${Number(product.id)})">Delete</button>
                    </div>
                    <a class="btn btn-outline mt-3" style="width:100%;" href="vendor-add-product.html?id=${encodeURIComponent(product.id)}#availability">Manage unavailable dates</a>
                </div>
            </div>`).join("") : '<p>You have not added any products yet.</p>';
    } catch (error) {
        container.innerHTML = `<p role="alert">${escapeHtml(error.message)}</p>`;
    }
}

async function deleteVendorProduct(productId) {
    if (!window.confirm("Delete this product? Products with rental history will be archived instead.")) return;
    const result = await api("products", { method: "DELETE" }, "&id=" + encodeURIComponent(productId));
    if (!result.success) {
        alert(result.message || "Could not delete the product.");
        return;
    }
    renderVendorProducts();
}

async function renderVendorOrders() {
    const tbody = document.getElementById("vendorOrdersBody");
    if (!tbody) return;
    const result = await api("vendor-orders");
    if (!result.success) {
        tbody.innerHTML = `<tr><td colspan="7">${escapeHtml(result.message || "Could not load orders.")}</td></tr>`;
        return;
    }
    const nextStatuses = {
        pending: [["accepted", "Accept"], ["rejected", "Reject"]],
        accepted: [["preparing", "Preparing"], ["cancelled", "Cancel"]],
        preparing: [["ready_for_pickup", "Ready for pickup"], ["cancelled", "Cancel"]],
        ready_for_pickup: [["picked_up", "Picked up"], ["cancelled", "Cancel"]],
        picked_up: [["returned", "Returned"]]
    };
    tbody.innerHTML = result.orders.length ? result.orders.map(order => {
        const options = nextStatuses[order.vendor_status] || [];
        return `<tr>
            <td>${escapeHtml(orderCode(order.order_id))}</td>
            <td>${escapeHtml(order.product_name)}${order.size ? `<br><small>Size: ${escapeHtml(order.size)}</small>` : ""}</td>
            <td>${escapeHtml(order.first_name)} ${escapeHtml(order.last_name)}<br><small>${escapeHtml(order.email)}</small></td>
            <td>${escapeHtml(order.pickup_date)} – ${escapeHtml(order.return_date)} · Qty ${Number(order.quantity)}</td>
            <td>${statusBadge(order.vendor_status)}</td>
            <td>Rs. ${Number(order.line_total).toLocaleString()}</td>
            <td>${options.length ? `<select class="form-control" onchange="updateOrderStatus(${Number(order.order_item_id)}, this.value)"><option value="">Update…</option>${options.map(([value, label]) => `<option value="${value}">${label}</option>`).join("")}</select>` : "—"}</td>
        </tr>`;
    }).join("") : '<tr><td colspan="7">No rental requests yet.</td></tr>';
}

async function updateOrderStatus(orderItemId, newStatus) {
    if (!newStatus) return;
    const result = await api("vendor-orders", { body: { order_item_id: orderItemId, status: newStatus } });
    if (!result.success) alert(result.message || "Could not update the rental status.");
    renderVendorOrders();
}

function renderShopListLegacy() {
    const container = document.getElementById("shopsGrid");
    if (!container) return;
    
    let html = '';
    shops.forEach(s => {
        const productCount = mockProducts.filter(p => p.shopId === s.id).length;
        html += `
        <div class="card">
            <div class="card-body text-center">
                <img src="${s.logo}" width="60" style="margin: 0 auto 15px;">
                <h3 class="card-title">${s.name}</h3>
                <p class="text-muted mb-2">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle; margin-top:-2px;">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg> ${s.city}
                </p>
                <p style="font-size:13px;" class="mb-3">${s.category}</p>
                <div class="d-flex justify-between align-center mb-3 text-muted" style="font-size:12px;">
                    <span>★ ${s.rating}</span>
                    <span>${productCount} items</span>
                </div>
                <a href="shop-profile.html?id=${s.id}" class="btn btn-outline" style="width:100%">Visit Shop</a>
            </div>
        </div>`;
    });
    container.innerHTML = html;
}

function renderShopProfileLegacy() {
    const urlParams = new URLSearchParams(window.location.search);
    const shopId = parseInt(urlParams.get('id')) || 1;
    const shop = shops.find(s => s.id === shopId);
    
    if (!shop) return;
    
    const titleEl = document.getElementById("shopProfileName");
    const locationEl = document.getElementById("shopProfileLocation");
    const container = document.getElementById("shopProductsGrid");
    
    if (titleEl) titleEl.textContent = shop.name;
    if (locationEl) {
        locationEl.innerHTML = `
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle; margin-top:-2px;">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
            </svg> ${shop.city} &middot; ★ ${shop.rating}`;
    }
    
    if (container) {
        let html = '';
        mockProducts.filter(p => p.shopId === shopId).forEach(p => {
            let badgeClass = p.availability === 'Available' ? 'badge-available' : (p.availability === 'Limited' ? 'badge-limited' : 'badge-unavailable');
            html += `
            <div class="card" data-product-id="${p.id}">
                <div class="card-img"><img src="${p.photos[0]}" alt="${p.name}" onerror="this.onerror=null; this.src='images/bridal_model.png';"></div>
                <div class="card-body text-center">
                    <span class="badge ${badgeClass} mb-2">${p.availability}</span>
                    <p style="font-size:12px; color:var(--muted)">${p.category}</p>
                    <h3 class="card-title">${p.name}</h3>
                    <div class="price">Rs. ${p.price} / day</div>
                    <a href="product-details.html?id=${p.id}" class="btn btn-outline" style="width:100%">View Details</a>
                </div>
            </div>`;
        });
        container.innerHTML = html;
    }
}


async function loadVendorProfile() {
    const form = document.getElementById("vendorShopForm");
    if (!form) return;
    const result = await api("vendor-shops");
    if (!result.success) return setPageMessage(form, result.message || "Could not load your shop profile.", true);
    const shopsList = result.shops || [];
    const select = document.getElementById("vendorShopSelect");
    select.innerHTML = shopsList.map(item => `<option value="${Number(item.shop_id)}">${escapeHtml(item.shop_name)} (${escapeHtml(item.status)})</option>`).join("") || '<option value="">No shops yet</option>';
    form.querySelectorAll("[data-shop-field]").forEach(field => field.disabled = !shopsList.length);
    document.getElementById("vendorShopActivate").disabled = !shopsList.length;
    document.getElementById("shopLogoUpload").disabled = !shopsList.length;
    if (shopsList.length) {
        const profile = result.profile || {};
        form.vendorShops = shopsList;
        document.getElementById("ownerName").value = `${profile.first_name || ""} ${profile.last_name || ""}`.trim();
        document.getElementById("ownerPhone").value = profile.phone || "";
        fillVendorShop(shopsList, select.value);
    } else document.getElementById("shopStatus").textContent = "Create a shop to get started.";
    if (!form.dataset.bound) {
        select.addEventListener("change", () => fillVendorShop(form.vendorShops || [], select.value));
        form.addEventListener("submit", saveVendorShopProfile);
        document.getElementById("vendorShopActivate").addEventListener("click", toggleVendorShop);
        document.getElementById("shopLogoUpload").addEventListener("change", uploadVendorShopLogo);
        form.dataset.bound = "true";
    }
    const newShopForm = document.getElementById("vendorNewShopForm");
    if (newShopForm && !newShopForm.dataset.bound) {
        newShopForm.addEventListener("submit", createVendorShop);
        newShopForm.dataset.bound = "true";
    }
}

function fillVendorShop(list, id) {
    const shop = list.find(item => String(item.shop_id) === String(id));
    if (!shop) return;
    document.getElementById("shopName").value = shop.shop_name || "";
    document.getElementById("shopEmail").value = shop.email || "";
    document.getElementById("shopPhone").value = shop.phone || "";
    document.getElementById("shopAddress").value = shop.address || "";
    document.getElementById("shopDescription").value = shop.description || "";
    document.getElementById("shopLogoPreview").src = shop.logo_path || "images/logo.svg";
    document.getElementById("shopStatus").textContent = "Shop status: " + shop.status;
    const button = document.getElementById("vendorShopActivate");
    button.textContent = shop.status === "approved" ? "Deactivate Shop" : "Activate Shop";
    button.disabled = !["approved", "inactive"].includes(shop.status);
}

async function saveVendorShopProfile(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const result = await api("vendor-shops", { method: "PUT", body: {
        shop_id: Number(document.getElementById("vendorShopSelect").value),
        shop_name: document.getElementById("shopName").value.trim(),
        owner_name: document.getElementById("ownerName").value.trim(),
        owner_phone: document.getElementById("ownerPhone").value.trim(),
        phone: document.getElementById("shopPhone").value.trim(),
        email: document.getElementById("shopEmail").value.trim(),
        address: document.getElementById("shopAddress").value.trim(),
        description: document.getElementById("shopDescription").value.trim()
    }});
    setPageMessage(form, result.message, !result.success);
    if (result.success) loadVendorProfile();
}

async function createVendorShop(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const result = await api("vendor-shops", { body: {
        shop_name: document.getElementById("newShopName").value.trim(),
        email: document.getElementById("newShopEmail").value.trim(),
        phone: document.getElementById("newShopPhone").value.trim(),
        address: document.getElementById("newShopAddress").value.trim(),
        description: document.getElementById("newShopDescription").value.trim()
    }});
    setPageMessage(form, result.message, !result.success);
    if (result.success) {
        form.reset();
        await loadVendorProfile();
    }
}

async function toggleVendorShop() {
    const button = document.getElementById("vendorShopActivate");
    const result = await api("vendor-shops", { method: "PATCH", body: {
        shop_id: Number(document.getElementById("vendorShopSelect").value),
        is_active: button.textContent.trim() === "Activate Shop"
    }});
    setPageMessage(document.getElementById("vendorShopForm"), result.message, !result.success);
    if (result.success) loadVendorProfile();
}

async function uploadVendorShopLogo(event) {
    const input = event.currentTarget;
    if (!input.files.length) return;
    const body = new FormData();
    body.append("shop_id", document.getElementById("vendorShopSelect").value);
    body.append("logo", input.files[0]);
    const result = await api("shop-logo", { body });
    setPageMessage(document.getElementById("vendorShopForm"), result.message, !result.success);
    if (result.success) document.getElementById("shopLogoPreview").src = result.logo_path;
    input.value = "";
}

function renderShopList() {
    const container = document.getElementById("shopsGrid");
    if (!container) return;
    api("shops").then(result => {
        container.innerHTML = result.success && result.shops.length ? result.shops.map(shop => `
            <div class="card"><div class="card-body text-center">
                <img src="${escapeHtml(shop.logo_path || "images/logo.svg")}" width="60" alt="${escapeHtml(shop.shop_name)} logo" style="margin:0 auto 15px;">
                <h3 class="card-title">${escapeHtml(shop.shop_name)}</h3>
                <p class="text-muted mb-2">${escapeHtml(shop.address || "Sri Lanka")}</p>
                <p class="mb-3">${escapeHtml(shop.description || "")}</p>
                <p class="text-muted mb-3">${Number(shop.product_count)} products</p>
                <a href="shop-profile.html?id=${Number(shop.shop_id)}" class="btn btn-outline" style="width:100%">Visit Shop</a>
            </div></div>`).join("") : `<p>${escapeHtml(result.message || "No approved shops are available yet.")}</p>`;
    }).catch(() => { container.innerHTML = "<p role='alert'>Could not load shops. Check that the server is running.</p>"; });
}

function renderShopProfile() {
    const id = Number(new URLSearchParams(location.search).get("id"));
    const title = document.getElementById("shopProfileName");
    const locationText = document.getElementById("shopProfileLocation");
    const container = document.getElementById("shopProductsGrid");
    if (!container || !title) return;
    if (!id) {
        title.textContent = "Shop not found";
        container.innerHTML = "<p>This shop is not available.</p>";
        return;
    }
    Promise.all([api("shops"), loadProducts()]).then(([shopsResult, products]) => {
        const shop = shopsResult.success ? shopsResult.shops.find(item => Number(item.shop_id) === id) : null;
        if (!shop) {
            title.textContent = "Shop not found";
            container.innerHTML = "<p>This shop is not available.</p>";
            return;
        }
        title.textContent = shop.shop_name;
        if (locationText) locationText.textContent = [shop.address, shop.phone, shop.email].filter(Boolean).join(" · ");
        const description = document.getElementById("shopProfileDescription");
        if (description) description.textContent = shop.description || "";
        container.innerHTML = products.filter(product => Number(product.shopId) === id).map(product => `
            <div class="card product-card" data-product-id="${Number(product.id)}">
                <div class="card-img"><img src="${escapeHtml(product.image)}" alt="${escapeHtml(product.name)}"></div>
                <div class="card-body text-center">
                    <span class="badge ${product.availability === "Available" ? "badge-available" : product.availability === "Limited" ? "badge-limited" : "badge-unavailable"} mb-2">${escapeHtml(product.availability)}</span>
                    <p class="text-muted">${escapeHtml(product.category)} · ${escapeHtml(shop.shop_name)}</p>
                    <h3 class="card-title">${escapeHtml(product.name)}</h3>
                    <div class="price">Rs. ${product.price.toLocaleString()} / day</div>
                    <a href="product-details.html?id=${encodeURIComponent(product.id)}" class="btn btn-outline" style="width:100%">View Details</a>
                </div>
            </div>`).join("") || "<p>This shop has no active products yet.</p>";
        wireProductCards();
    }).catch(() => { container.innerHTML = "<p role='alert'>Could not load shop products. Check that the server is running.</p>"; });
}

async function renderAdminOverview() {
    const target = document.getElementById("adminShopCount");
    if (!target) return;
    const result = await api("admin-overview");
    if (!result.success) {
        target.textContent = result.message || "Unavailable";
        return;
    }
    const data = result.overview;
    target.textContent = Number(data.shops).toLocaleString();
    document.getElementById("adminRentalVolume").textContent = "Rs. " + Number(data.platform_rentals).toLocaleString();
    document.getElementById("adminActiveOrders").textContent = Number(data.active_orders).toLocaleString();
    document.getElementById("adminVendorCount").textContent = Number(data.vendors).toLocaleString();
    document.getElementById("adminPendingVendors").textContent = Number(data.pending_vendors).toLocaleString();
    document.getElementById("adminProductCount").textContent = Number(data.products).toLocaleString();
}

async function renderAdminManagement() {
    const vendorBody = document.getElementById("adminVendorsBody");
    const shopBody = document.getElementById("adminShopsBody");
    const productBody = document.getElementById("adminProductsBody");
    if (!vendorBody || !shopBody || !productBody) return;
    const [vendorResult, shopResult, productResult] = await Promise.all([
        api("admin-vendors"), api("shops", {}, "&manage=1"), api("products", {}, "&manage=1")
    ]);
    if (!vendorResult.success) vendorBody.innerHTML = `<tr><td colspan="4">${escapeHtml(vendorResult.message || "Could not load vendors.")}</td></tr>`;
    else {
        const vendors = [...new Map(vendorResult.vendors.map(vendor => [vendor.user_id, vendor])).values()];
        vendorBody.innerHTML = vendors.length ? vendors.map(vendor => {
            const action = vendor.vendor_status === "pending"
                ? `<button class="btn btn-primary" onclick="changeVendorStatus(${Number(vendor.user_id)},'approve')">Approve</button> <button class="btn btn-outline" onclick="changeVendorStatus(${Number(vendor.user_id)},'reject')">Reject</button>`
                : vendor.vendor_status === "suspended"
                    ? `<button class="btn btn-outline" onclick="changeVendorStatus(${Number(vendor.user_id)},'reactivate')">Reactivate</button>`
                    : `<button class="btn btn-outline" onclick="changeVendorStatus(${Number(vendor.user_id)},'suspend')">Suspend</button>`;
            return `<tr><td>${escapeHtml(vendor.first_name)} ${escapeHtml(vendor.last_name)}</td><td>${escapeHtml(vendor.email)}</td><td>${statusBadge(vendor.vendor_status)}</td><td>${action}</td></tr>`;
        }).join("") : '<tr><td colspan="4">No vendors registered.</td></tr>';
    }
    if (!shopResult.success) shopBody.innerHTML = `<tr><td colspan="5">${escapeHtml(shopResult.message || "Could not load shops.")}</td></tr>`;
    else shopBody.innerHTML = shopResult.shops.length ? shopResult.shops.map(shop => {
        const status = shop.status === "pending"
            ? `<button class="btn btn-primary" onclick="changeShopStatus(${Number(shop.shop_id)},'approved')">Approve</button> <button class="btn btn-outline" onclick="changeShopStatus(${Number(shop.shop_id)},'rejected')">Reject</button>`
            : shop.status === "approved"
                ? `<button class="btn btn-outline" onclick="changeShopStatus(${Number(shop.shop_id)},'inactive')">Deactivate</button>`
                : shop.status === "inactive"
                    ? `<button class="btn btn-outline" onclick="changeShopStatus(${Number(shop.shop_id)},'approved')">Reactivate</button>`
                    : `<button class="btn btn-outline" onclick="changeShopStatus(${Number(shop.shop_id)},'approved')">Approve</button>`;
        return `<tr><td>${escapeHtml(shop.shop_name)}</td><td>${escapeHtml(shop.first_name)} ${escapeHtml(shop.last_name)}</td><td>${escapeHtml(shop.address || "—")}</td><td>${statusBadge(shop.status)}</td><td>${status}</td></tr>`;
    }).join("") : '<tr><td colspan="5">No shops registered.</td></tr>';
    if (!productResult.success) productBody.innerHTML = `<tr><td colspan="6">${escapeHtml(productResult.message || "Could not load products.")}</td></tr>`;
    else productBody.innerHTML = productResult.products.length ? productResult.products.map(product => `<tr>
        <td>${escapeHtml(product.product_name)}</td><td>${escapeHtml(product.shop_name || "—")}</td>
        <td>${escapeHtml(product.category_name || "—")}</td><td>Rs. ${Number(product.price_per_day).toLocaleString()}</td>
        <td>${Number(product.stock)}</td><td>${statusBadge(product.status)}</td></tr>`).join("") : '<tr><td colspan="6">No vendor products yet.</td></tr>';
}

async function changeVendorStatus(vendorId, decision) {
    const result = await api("admin-vendors", { body: { vendor_id: vendorId, decision } });
    if (!result.success) alert(result.message || "Could not update vendor account.");
    renderAdminManagement();
    renderAdminOverview();
}

async function changeShopStatus(shopId, status) {
    const result = await api("shops", { method: "PATCH", body: { shop_id: shopId, status } }, "&manage=1");
    if (!result.success) alert(result.message || "Could not update shop status.");
    renderAdminManagement();
    renderAdminOverview();
}

async function renderAdminOrders() {
    const tbody = document.getElementById("adminOrdersBody");
    if (!tbody) return;
    const result = await api("orders");
    if (!result.success) {
        tbody.innerHTML = `<tr><td colspan="7">${escapeHtml(result.message || "Could not load orders.")}</td></tr>`;
        return;
    }
    const orderStatuses = [
        ["pending", "Pending"],
        ["confirmed", "Confirmed"],
        ["processing", "Processing"],
        ["ready_for_pickup", "Ready for Pickup"],
        ["picked_up", "Picked Up"],
        ["returned", "Returned"],
        ["cancelled", "Cancelled"]
    ];
    tbody.innerHTML = result.orders.length ? result.orders.map(order => `<tr>
        <td>${escapeHtml(orderCode(order.order_id))}<br><small>${escapeHtml(order.created_at || "")}</small></td>
        <td>${escapeHtml(order.first_name)} ${escapeHtml(order.last_name)}<br><small>${escapeHtml(order.email)}</small></td>
        <td>Rs. ${Number(order.rental_total).toLocaleString()}</td>
        <td>Rs. ${Number(order.deposit_total).toLocaleString()}</td>
        <td>Rs. ${Number(order.grand_total).toLocaleString()}</td>
        <td>${statusBadge(order.status)}</td>
        <td>
            <select class="form-control" style="font-size:12px; padding:4px 8px; width:auto;" onchange="updateAdminOrderStatus(${Number(order.order_id)}, this.value)">
                <option value="">Update...</option>
                ${orderStatuses.map(([val, label]) => `<option value="${val}" ${val === order.status ? "selected" : ""}>${label}</option>`).join("")}
            </select>
        </td>
    </tr>`).join("") : '<tr><td colspan="7">No orders yet.</td></tr>';
}

async function updateAdminOrderStatus(orderId, newStatus) {
    if (!newStatus) return;
    try {
        const res = await api("update-order", {
            method: "POST",
            body: { order_id: Number(orderId), status: newStatus }
        });
        if (!res.success) {
            alert(res.message || "Failed to update order status.");
        }
        renderAdminOrders();
    } catch(e) {
        alert("Could not reach the server.");
    }
}

/* ----------------------------------------------------------
   11) RUN ON PAGE LOAD
   ---------------------------------------------------------- */
document.addEventListener("DOMContentLoaded", () => {
    const hamburger = document.getElementById("hamburger");
    const navLinks = document.getElementById("navLinks");

    if (hamburger && navLinks) {
        hamburger.setAttribute("aria-expanded", "false");

        const closeMenu = () => {
            navLinks.classList.remove("open", "active");
            hamburger.setAttribute("aria-expanded", "false");
            document.body.classList.remove("menu-open");
        };

        hamburger.addEventListener("click", (event) => {
            event.stopPropagation();
            toggleMenu();
        });

        navLinks.querySelectorAll("a").forEach((link) => {
            link.addEventListener("click", closeMenu);
        });

        document.addEventListener("click", (event) => {
            if (
                navLinks.classList.contains("open") &&
                !navLinks.contains(event.target) &&
                !hamburger.contains(event.target)
            ) {
                closeMenu();
            }
        });

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closeMenu();
        });

        window.addEventListener("resize", () => {
            if (window.innerWidth > 860) closeMenu();
        });
    }

    updateCartCount();
    updateHeader();
    wireSignOut();
    renderProductDetails();
    renderHistory();
    renderAccount();
    initTrackingPage();

    // Vendor & Shop initialization
    renderVendorProducts();
    renderVendorOrders();
    renderVendorDashboard();
    loadVendorProfile();
    renderAdminOverview();
    renderAdminManagement();
    renderAdminOrders();
    renderShopList();
    renderShopProfile();
    wireProductCards();
});
