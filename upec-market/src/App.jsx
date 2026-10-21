import React, { useState, useMemo, useEffect, useRef, useCallback } from 'react';
import { apiRequest, getApiToken, setApiToken } from './api.js';
import { 
  Search, ShoppingCart, MessageSquare, Heart, User, Plus, ShieldCheck, 
  DollarSign, Recycle, Users, BookOpen, Laptop, Sofa, Shirt, Dumbbell, 
  Guitar, Sparkles, MoreHorizontal, CheckCircle, XCircle, Bell, CreditCard,
  Home, FileText, HelpCircle, ChevronRight, MapPin, Trash2, Send, X, Star
} from 'lucide-react';

const categories = [
  { name: 'Libros y apuntes', icon: <BookOpen size={24} /> },
  { name: 'Tecnología', icon: <Laptop size={24} /> },
  { name: 'Hogar y oficina', icon: <Sofa size={24} /> },
  { name: 'Ropa y accesorios', icon: <Shirt size={24} /> },
  { name: 'Deportes', icon: <Dumbbell size={24} /> },
  { name: 'Instrumentos musicales', icon: <Guitar size={24} /> },
  { name: 'Belleza y cuidado personal', icon: <Sparkles size={24} /> },
  { name: 'Otros', icon: <MoreHorizontal size={24} /> },
];

function StarRating({ rating, size = 16, interactive = false, onChange }) {
  return (
    <div className="flex gap-0.5">
      {[1, 2, 3, 4, 5].map((star) => (
        <Star
          key={star}
          size={size}
          className={`${star <= rating ? 'fill-yellow-400 text-yellow-400' : 'text-gray-300'} ${interactive ? 'cursor-pointer hover:scale-110 transition' : ''}`}
          onClick={() => interactive && onChange && onChange(star)}
        />
      ))}
    </div>
  );
}

export default function UPECMarket() {
  // --- ESTADOS PRINCIPALES ---
  const [userRole, setUserRole] = useState('comprador');
  const [currentUser, setCurrentUser] = useState(null);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');
  const [isRegistering, setIsRegistering] = useState(false);
  const [currentPage, setCurrentPage] = useState('inicio');
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedCategory, setSelectedCategory] = useState(null);
  
  // --- MODALES ---
  const [showPublishModal, setShowPublishModal] = useState(false);
  const [showPaymentModal, setShowPaymentModal] = useState(null);
  const [showProductDetail, setShowProductDetail] = useState(null);
  const [showMessages, setShowMessages] = useState(false);
  const [showReviewModal, setShowReviewModal] = useState(null);
  const [showChatModal, setShowChatModal] = useState(null);
  
  // --- DATOS ---
  const [products, setProducts] = useState([]);
  const [myProducts, setMyProducts] = useState([]);
  const [adminProducts, setAdminProducts] = useState([]);
  const [categoryList, setCategoryList] = useState([]);
  const [favorites, setFavorites] = useState([]);
  const [cart, setCart] = useState([]);
  const [messages, setMessages] = useState([]);
  const [orders, setOrders] = useState([]);
  const [reviews, setReviews] = useState({});
  const [chats, setChats] = useState({});
  const [newMessage, setNewMessage] = useState('');
  const [notification, setNotification] = useState(null);
  const [apiLoading, setApiLoading] = useState(true);
  const [isPublishing, setIsPublishing] = useState(false);
  const chatEndRef = useRef(null);

  const refreshProducts = useCallback(async () => {
    const [productResponse, categoriesResponse] = await Promise.all([
      apiRequest('/products?per_page=100'),
      apiRequest('/categories'),
    ]);
    setProducts(productResponse.data);
    setCategoryList(categoriesResponse);

    if (getApiToken()) {
      const [mineResponse, favoritesResponse, cartResponse, ordersResponse] = await Promise.all([
        apiRequest('/products/mine'),
        apiRequest('/favorites'),
        apiRequest('/cart'),
        apiRequest('/orders'),
      ]);
      setMyProducts(mineResponse);
      setFavorites(favoritesResponse.map(Number));
      setCart(cartResponse);
      setOrders(ordersResponse);

      if (currentUser?.role === 'admin') {
        setAdminProducts(await apiRequest('/admin/products/pending'));
      }
    }
  }, [currentUser?.role]);

  function showNotification(type, message) {
    setNotification({ type, message });
    setTimeout(() => setNotification(null), 3000);
  }

  useEffect(() => {
    let active = true;
    const initialize = async () => {
      try {
        const [productResponse, categoriesResponse] = await Promise.all([
          apiRequest('/products?per_page=100'),
          apiRequest('/categories'),
        ]);
        if (!active) return;
        setProducts(productResponse.data);
        setCategoryList(categoriesResponse);

        if (getApiToken()) {
          const { user } = await apiRequest('/auth/me');
          if (!active) return;
          setCurrentUser(user);
          setUserRole(user.role);
          setIsLoggedIn(true);
        }
      } catch (error) {
        if (active) showNotification('error', `No se pudo conectar con la API: ${error.message}`);
      } finally {
        if (active) setApiLoading(false);
      }
    };
    initialize();
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!isLoggedIn) return;
    const timer = window.setTimeout(() => {
      refreshProducts().catch(error => showNotification('error', error.message));
      apiRequest('/conversations')
        .then(setMessages)
        .catch(error => showNotification('error', error.message));
    }, 0);
    return () => window.clearTimeout(timer);
  }, [isLoggedIn, currentUser, refreshProducts]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('checkout') === 'success' && params.get('order') && params.get('session_id') && isLoggedIn) {
      apiRequest(`/orders/${params.get('order')}/confirm`, {
        method: 'POST',
        body: { session_id: params.get('session_id') },
      }).then(order => {
        if (order.status === 'Completado') {
          setShowReviewModal({ id: order.product_id, title: order.product });
          showNotification('success', 'Pago confirmado por Stripe.');
        } else {
          showNotification('info', 'El pago todavía está siendo confirmado.');
        }
        return refreshProducts();
      }).catch(error => showNotification('error', `No se pudo confirmar el pago: ${error.message}`))
        .finally(() => window.history.replaceState({}, '', window.location.pathname));
    } else if (params.get('checkout') === 'cancelled') {
      window.setTimeout(() => showNotification('info', 'Pago cancelado. No se realizó ningún cobro.'), 0);
      window.history.replaceState({}, '', window.location.pathname);
    }
  }, [isLoggedIn, refreshProducts]);

  useEffect(() => {
    if (!showChatModal?.conversationId) return undefined;
    const poll = window.setInterval(() => {
      apiRequest(`/conversations/${showChatModal.conversationId}/messages`)
        .then(thread => setChats(prev => ({ ...prev, [showChatModal.conversationId]: thread })))
        .catch(error => showNotification('error', error.message));
    }, 5000);
    return () => window.clearInterval(poll);
  }, [showChatModal?.conversationId]);

  // --- AUTO SCROLL EN CHAT ---
  useEffect(() => {
    if (chatEndRef.current) {
      chatEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [chats, showChatModal]);

  // --- UTILIDADES ---
  // --- PROMEDIO DE RESEÑAS ---
  const getAverageRating = (productId) => {
    const productReviews = reviews[productId] || [];
    const product = products.find(item => item.id === productId) || myProducts.find(item => item.id === productId);
    if (productReviews.length === 0 && product?.rating) return product.rating;
    if (productReviews.length === 0) return 0;
    const sum = productReviews.reduce((acc, r) => acc + r.rating, 0);
    return (sum / productReviews.length).toFixed(1);
  };

  // --- FILTRADO DE PRODUCTOS ---
  const filteredProducts = useMemo(() => {
    return products.filter(p => {
      const matchSearch = p.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          (p.category || '').toLowerCase().includes(searchTerm.toLowerCase());
      const matchCategory = !selectedCategory || p.category === selectedCategory;
      const matchStatus = p.status === 'Aprobado';
      return matchSearch && matchCategory && matchStatus;
    });
  }, [products, searchTerm, selectedCategory]);

  const handleLogin = async (e) => {
    e.preventDefault();
    try {
      const response = await apiRequest(isRegistering ? '/auth/register' : '/auth/login', {
        method: 'POST',
        body: isRegistering
          ? { name, email: email.toLowerCase(), password }
          : { email: email.toLowerCase(), password },
      });
      setApiToken(response.token);
      setCurrentUser(response.user);
      setUserRole(response.user.role);
      setIsLoggedIn(true);
      setPassword('');
      setName('');
      showNotification('success', isRegistering ? 'Cuenta creada correctamente.' : 'Sesión iniciada.');
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  // --- FAVORITOS ---
  const toggleFavorite = async (id) => {
    if (!isLoggedIn) return showNotification('error', 'Inicia sesión para guardar favoritos.');
    try {
      const isFavorite = favorites.includes(id);
      await apiRequest(`/products/${id}/favorite`, { method: isFavorite ? 'DELETE' : 'POST' });
      setFavorites(prev => isFavorite ? prev.filter(f => f !== id) : [...prev, id]);
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  // --- CARRITO ---
  const addToCart = async (product) => {
    if (!isLoggedIn) {
      showNotification('error', 'Debes iniciar sesión para agregar al carrito.');
      return;
    }
    if (cart.find(c => c.id === product.id)) return showNotification('info', 'Este producto ya está en tu carrito.');
    try {
      await apiRequest(`/cart/${product.id}`, { method: 'POST' });
      setCart(prev => [...prev, product]);
      showNotification('success', `${product.title} añadido al carrito.`);
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  const removeFromCart = async (id) => {
    try {
      await apiRequest(`/cart/${id}`, { method: 'DELETE' });
      setCart(prev => prev.filter(c => c.id !== id));
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  // --- PUBLICAR ---
  const handlePublish = async (e) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    setIsPublishing(true);
    try {
      const product = await apiRequest('/products', {
        method: 'POST',
        body: formData,
      });
      setMyProducts(prev => [product, ...prev.filter(item => item.id !== product.id)]);
      setShowPublishModal(false);
      showNotification('success', 'Producto enviado a revisión.');
      refreshProducts().catch(error => showNotification('error', `El producto se guardó, pero no se pudo actualizar la lista: ${error.message}`));
    } catch (error) {
      showNotification('error', error.message);
    } finally {
      setIsPublishing(false);
    }
  };

  // --- APROBAR (ADMIN) ---
  const approveProduct = async (id) => {
    try {
      await apiRequest(`/admin/products/${id}/approve`, { method: 'POST' });
      await refreshProducts();
      showNotification('success', 'Producto aprobado y publicado.');
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  const rejectProduct = async (id) => {
    try {
      await apiRequest(`/admin/products/${id}/reject`, { method: 'POST', body: {} });
      await refreshProducts();
      showNotification('success', 'Producto rechazado.');
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  // --- PAGO (STRIPE SIMULADO) ---
  const handlePayment = (product) => {
    if (!isLoggedIn) {
      showNotification('error', 'Debes iniciar sesión para comprar.');
      return;
    }
    if (!isLoggedIn) return showNotification('error', 'Debes iniciar sesión para comprar.');
    if (product.type !== 'En venta') return showNotification('info', 'Este producto está disponible para intercambio, no para pago online.');
    const total = product.price;
    const commission = total * 0.03;
    const sellerReceives = total - commission;
    setShowPaymentModal({ product, total, commission, sellerReceives });
  };

  const confirmPayment = async () => {
    try {
      const response = await apiRequest(`/products/${showPaymentModal.product.id}/checkout`, { method: 'POST' });
      setShowPaymentModal(null);
      window.location.assign(response.checkout_url);
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  // --- AÑADIR RESEÑA ---
  const handleAddReview = async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const rating = parseInt(formData.get('rating'));
    const comment = formData.get('comment');
    const productId = showReviewModal.id;

    try {
      const newReview = await apiRequest(`/products/${productId}/reviews`, {
        method: 'POST',
        body: { rating, comment },
      });
      setReviews(prev => ({ ...prev, [productId]: [...(prev[productId] || []), newReview] }));
      setShowReviewModal(null);
      showNotification('success', '¡Gracias por tu reseña!');
      await refreshProducts();
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  // --- ENVIAR MENSAJE EN CHAT ---
  const handleSendMessage = async (productId) => {
    if (!newMessage.trim()) return;
    try {
      const response = await apiRequest(`/conversations/${productId}/messages`, {
        method: 'POST',
        body: { text: newMessage },
      });
      setChats(prev => ({ ...prev, [productId]: [...(prev[productId] || []), response] }));
      setNewMessage('');
      apiRequest(`/conversations/${productId}/messages`).then(messages => {
        setChats(prev => ({ ...prev, [productId]: messages }));
      }).catch(error => showNotification('error', error.message));
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  const openProductDetail = async (product) => {
    setShowProductDetail(product);
    try {
      const detail = await apiRequest(`/products/${product.id}`);
      setShowProductDetail(detail);
      if (detail.reviews) setReviews(prev => ({ ...prev, [product.id]: detail.reviews }));
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  const startConversation = async (product) => {
    if (!isLoggedIn) return showNotification('error', 'Inicia sesión para contactar al vendedor.');
    try {
      const conversation = await apiRequest(`/products/${product.id}/conversations`, { method: 'POST' });
      const thread = await apiRequest(`/conversations/${conversation.id}/messages`);
      setChats(prev => ({ ...prev, [conversation.id]: thread }));
      setMessages(await apiRequest('/conversations'));
      setShowChatModal({ ...product, conversationId: conversation.id });
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  const openConversation = async (conversation) => {
    try {
      const thread = await apiRequest(`/conversations/${conversation.id}/messages`);
      setChats(prev => ({ ...prev, [conversation.id]: thread }));
      setShowMessages(false);
      setShowChatModal({
        id: conversation.product_id,
        title: conversation.title,
        seller: conversation.other_user,
        conversationId: conversation.id,
      });
      setMessages(await apiRequest('/conversations'));
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  const switchRole = async () => {
    const role = userRole === 'vendedor' ? 'comprador' : 'vendedor';
    try {
      const response = await apiRequest('/auth/role', { method: 'PUT', body: { role } });
      setCurrentUser(response.user);
      setUserRole(response.user.role);
      showNotification('success', `Modo ${response.user.role} activado.`);
    } catch (error) {
      showNotification('error', error.message);
    }
  };

  const logout = async () => {
    try {
      await apiRequest('/auth/logout', { method: 'POST' });
    } catch (error) {
      showNotification('error', error.message);
    } finally {
      setApiToken(null);
      setCurrentUser(null);
      setIsLoggedIn(false);
      setUserRole('comprador');
      setCurrentPage('inicio');
      setMyProducts([]);
      setAdminProducts([]);
      setFavorites([]);
      setCart([]);
      setOrders([]);
      setMessages([]);
    }
  };

  // --- NAVEGACIÓN ---
  const navigateTo = (page) => {
    setCurrentPage(page);
    setSelectedCategory(null);
    setSearchTerm('');
  };

  return (
    <div className="min-h-screen bg-gray-50 font-sans text-gray-800 relative">

      {/* NOTIFICACIONES */}
      {notification && (
        <div className={`fixed top-4 right-4 z-[100] p-4 rounded-lg shadow-lg flex items-center gap-2 ${notification.type === 'success' ? 'bg-green-100 text-green-800' : notification.type === 'error' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'}`}>
          {notification.type === 'success' ? <CheckCircle size={20} /> : notification.type === 'error' ? <XCircle size={20} /> : <Bell size={20} />}
          {notification.message}
        </div>
      )}

      {/* HEADER */}
      <header className="bg-upec-dark text-white sticky top-0 z-50 shadow-lg">
        <div className="container mx-auto px-4 py-4 flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-2 cursor-pointer" onClick={() => navigateTo('inicio')}>
            <div className="bg-upec-green p-2 rounded-lg"><ShoppingCart size={24} className="text-white" /></div>
            <div>
              <h1 className="text-xl font-bold leading-tight">UPEC Market</h1>
              <p className="text-xs text-gray-400 hidden md:block">Marketplace para estudiantes UPEC</p>
            </div>
          </div>

          <div className="flex-1 max-w-xl relative">
            <input 
              type="text" 
              placeholder="Buscar productos, categorías..." 
              value={searchTerm}
              onChange={(e) => { setSearchTerm(e.target.value); setCurrentPage('explorar'); }}
              className="w-full px-4 py-2 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-upec-green" 
            />
            <Search className="absolute right-3 top-2.5 text-gray-400" size={20} />
          </div>

          <div className="flex items-center gap-4">
            {isLoggedIn ? (
              <div className="flex items-center gap-4">
                <button onClick={() => navigateTo('favoritos')} className="relative hover:text-upec-green transition">
                  <Heart size={24} />
                  {favorites.length > 0 && (
                    <span className="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">{favorites.length}</span>
                  )}
                </button>
                <button onClick={() => setShowMessages(true)} className="relative hover:text-upec-green transition">
                  <MessageSquare size={24} />
                  {messages.filter(m => m.unread).length > 0 && (
                    <span className="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">{messages.filter(m => m.unread).length}</span>
                  )}
                </button>
                <button onClick={() => navigateTo('carrito')} className="relative hover:text-upec-green transition">
                  <ShoppingCart size={24} />
                  {cart.length > 0 && (
                    <span className="absolute -top-2 -right-2 bg-upec-green text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">{cart.length}</span>
                  )}
                </button>
                <div className="flex items-center gap-2 bg-gray-800 px-3 py-1.5 rounded-full">
                  <User size={18} />
                  <span className="text-sm font-medium hidden md:block">{userRole === 'admin' ? 'Administrador' : userRole === 'vendedor' ? 'Vendedor' : 'Comprador'}</span>
                  {userRole !== 'admin' && <button onClick={switchRole} className="text-xs bg-upec-green px-2 py-1 rounded hover:bg-green-600 transition">Cambiar</button>}
                  <button onClick={logout} className="text-xs text-gray-300 hover:text-white">Salir</button>
                </div>
              </div>
            ) : (
              <div className="flex flex-col items-end gap-1">
                <form onSubmit={handleLogin} className="flex flex-wrap items-center justify-end gap-2">
                  {isRegistering && <input type="text" placeholder="Nombre" value={name} onChange={e => setName(e.target.value)} className="px-3 py-1.5 rounded text-gray-900 text-sm" required />}
                  <input type="email" placeholder="Correo @upec.edu.ec" value={email} onChange={(e) => setEmail(e.target.value)} className="px-3 py-1.5 rounded text-gray-900 text-sm focus:outline-none" required />
                  <input type="password" placeholder="Contraseña" value={password} onChange={e => setPassword(e.target.value)} minLength={8} className="px-3 py-1.5 rounded text-gray-900 text-sm focus:outline-none" required />
                  <button type="submit" className="bg-upec-green px-4 py-1.5 rounded font-medium text-sm hover:bg-green-600 transition">{isRegistering ? 'Crear cuenta' : 'Ingresar'}</button>
                </form>
                <button onClick={() => setIsRegistering(value => !value)} className="text-xs text-gray-300 hover:text-white">{isRegistering ? 'Ya tengo cuenta' : 'Crear cuenta institucional'}</button>
              </div>
            )}
          </div>
        </div>

        <nav className="border-t border-gray-700">
          <div className="container mx-auto px-4 flex gap-6 overflow-x-auto py-3 text-sm font-medium">
            <button onClick={() => navigateTo('inicio')} className={`flex items-center gap-2 transition whitespace-nowrap ${currentPage === 'inicio' ? 'text-upec-green border-b-2 border-upec-green pb-1' : 'hover:text-upec-green'}`}>
              <Home size={16} /> Inicio
            </button>
            <button onClick={() => navigateTo('explorar')} className={`flex items-center gap-2 transition whitespace-nowrap ${currentPage === 'explorar' ? 'text-upec-green border-b-2 border-upec-green pb-1' : 'hover:text-upec-green'}`}>
              <Search size={16} /> Explorar
            </button>
            {(userRole === 'vendedor' || userRole === 'admin') && (
              <button onClick={() => setShowPublishModal(true)} className="flex items-center gap-2 hover:text-upec-green transition text-upec-green font-bold whitespace-nowrap">
                <Plus size={16} /> Publicar
              </button>
            )}
            <button onClick={() => navigateTo('mis-publicaciones')} className={`flex items-center gap-2 transition whitespace-nowrap ${currentPage === 'mis-publicaciones' ? 'text-upec-green border-b-2 border-upec-green pb-1' : 'hover:text-upec-green'}`}>
              <FileText size={16} /> Mis publicaciones
            </button>
            <button onClick={() => setShowMessages(true)} className="flex items-center gap-2 hover:text-upec-green transition whitespace-nowrap">
              <MessageSquare size={16} /> Mensajes
            </button>
            <button onClick={() => navigateTo('favoritos')} className={`flex items-center gap-2 transition whitespace-nowrap ${currentPage === 'favoritos' ? 'text-upec-green border-b-2 border-upec-green pb-1' : 'hover:text-upec-green'}`}>
              <Heart size={16} /> Favoritos
            </button>
            {isLoggedIn && <button onClick={() => navigateTo('compras')} className={`flex items-center gap-2 transition whitespace-nowrap ${currentPage === 'compras' ? 'text-upec-green border-b-2 border-upec-green pb-1' : 'hover:text-upec-green'}`}><CreditCard size={16} /> Mis compras</button>}
            {isLoggedIn && <button onClick={() => navigateTo('admin')} className={`flex items-center gap-2 transition whitespace-nowrap ${currentPage === 'admin' ? 'text-upec-green border-b-2 border-upec-green pb-1' : 'hover:text-upec-green'}`}><ShieldCheck size={16} /> Moderación</button>}
            <button onClick={() => navigateTo('ayuda')} className={`flex items-center gap-2 transition whitespace-nowrap ${currentPage === 'ayuda' ? 'text-upec-green border-b-2 border-upec-green pb-1' : 'hover:text-upec-green'}`}>
              <HelpCircle size={16} /> Ayuda
            </button>
          </div>
        </nav>
      </header>
      {apiLoading && <div className="container mx-auto px-4 py-6 text-sm text-gray-500">Conectando con el marketplace...</div>}

      {/* PÁGINA: INICIO */}
      {currentPage === 'inicio' && (
        <>
          <section className="bg-upec-dark text-white relative overflow-hidden">
            <div className="absolute inset-0 bg-black/40 z-10"></div>
            <img src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&q=80&w=1600" alt="Estudiantes" className="absolute inset-0 w-full h-full object-cover" />
            <div className="container mx-auto px-4 py-20 relative z-20">
              <div className="md:w-1/2 space-y-6">
                <h2 className="text-4xl md:text-5xl font-bold leading-tight">Compra, vende e intercambia <span className="text-upec-green">entre estudiantes de la UPEC</span></h2>
                <p className="text-lg text-gray-200">Encuentra lo que necesitas, vende lo que ya no usas e intercambia de forma segura con tu comunidad.</p>
                <div className="flex gap-4 flex-wrap">
                  <button onClick={() => navigateTo('explorar')} className="bg-upec-green text-white px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:bg-green-600 transition">Explorar productos <ChevronRight size={20} /></button>
                  {(userRole === 'vendedor' || userRole === 'admin') && (
                    <button onClick={() => setShowPublishModal(true)} className="bg-white text-upec-dark px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:bg-gray-100 transition">Publicar producto <Plus size={20} /></button>
                  )}
                </div>
              </div>
            </div>
          </section>

          <section className="container mx-auto px-4 -mt-10 relative z-30">
            <div className="bg-white rounded-xl shadow-lg p-6 grid grid-cols-1 md:grid-cols-4 gap-6">
              {[
                { icon: <ShieldCheck className="text-upec-green" size={32} />, title: 'Comunidad UPEC', desc: 'Registro con correo institucional.' },
                { icon: <DollarSign className="text-upec-green" size={32} />, title: 'Ahorra dinero', desc: 'Encuentra productos a precios accesibles.' },
                { icon: <Recycle className="text-upec-green" size={32} />, title: 'Reutiliza y cuida el planeta', desc: 'Dale una segunda vida a los productos.' },
                { icon: <Users className="text-upec-green" size={32} />, title: 'Comunidad UPEC', desc: 'Conecta con estudiantes de todas las carreras.' },
              ].map((item, i) => (
                <div key={i} className="flex items-start gap-4">
                  <div className="bg-green-50 p-3 rounded-full">{item.icon}</div>
                  <div>
                    <h4 className="font-bold text-sm">{item.title}</h4>
                    <p className="text-xs text-gray-500 mt-1">{item.desc}</p>
                  </div>
                </div>
              ))}
            </div>
          </section>

          <section className="container mx-auto px-4 py-12">
            <h3 className="text-2xl font-bold mb-6">Categorías</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
              {categoryList.map(cat => (
                <div 
                  key={cat.id}
                  onClick={() => { setSelectedCategory(cat.name); setCurrentPage('explorar'); }}
                  className="bg-white p-4 rounded-xl shadow-sm hover:shadow-md transition cursor-pointer flex flex-col items-center text-center gap-3 border border-gray-100"
                >
                  <div className="text-gray-600">{categories.find(category => category.name === cat.name)?.icon || <MoreHorizontal size={24} />}</div>
                  <span className="text-xs font-medium">{cat.name}</span>
                </div>
              ))}
            </div>
          </section>

          <section className="container mx-auto px-4 py-8">
            <div className="flex justify-between items-center mb-6">
              <h3 className="text-2xl font-bold">Catálogo de productos</h3>
              <button onClick={() => navigateTo('explorar')} className="text-sm text-upec-green hover:underline">
                Ver catálogo
              </button>
            </div>
            <ProductGrid 
              products={filteredProducts} 
              favorites={favorites} 
              toggleFavorite={toggleFavorite} 
              handlePayment={handlePayment}
              addToCart={addToCart}
              approveProduct={approveProduct}
              rejectProduct={rejectProduct}
              setShowProductDetail={openProductDetail}
              getAverageRating={getAverageRating}
              reviews={reviews}
            />
          </section>
        </>
      )}

      {/* PÁGINA: EXPLORAR */}
      {currentPage === 'explorar' && (
        <section className="container mx-auto px-4 py-8">
          <div className="flex justify-between items-center mb-6">
            <h3 className="text-2xl font-bold">
              {selectedCategory ? `Categoría: ${selectedCategory}` : searchTerm ? `Búsqueda: "${searchTerm}"` : 'Todos los productos'}
            </h3>
            {(selectedCategory || searchTerm) && (
              <button onClick={() => { setSelectedCategory(null); setSearchTerm(''); }} className="text-sm text-upec-green flex items-center gap-1 hover:underline">
                <X size={16} /> Limpiar filtros
              </button>
            )}
          </div>
          {filteredProducts.length === 0 ? (
            <div className="text-center py-20 bg-white rounded-xl shadow-sm">
              <p className="text-gray-500 text-lg">No se encontraron productos.</p>
            </div>
          ) : (
            <ProductGrid 
              products={filteredProducts} 
              favorites={favorites} 
              toggleFavorite={toggleFavorite} 
              handlePayment={handlePayment}
              addToCart={addToCart}
              approveProduct={approveProduct}
              rejectProduct={rejectProduct}
              setShowProductDetail={openProductDetail}
              getAverageRating={getAverageRating}
              reviews={reviews}
            />
          )}
        </section>
      )}

      {/* PÁGINA: FAVORITOS */}
      {currentPage === 'favoritos' && (
        <section className="container mx-auto px-4 py-8">
          <h3 className="text-2xl font-bold mb-6">Mis favoritos ({favorites.length})</h3>
          {favorites.length === 0 ? (
            <div className="text-center py-20 bg-white rounded-xl shadow-sm">
              <Heart size={48} className="mx-auto text-gray-300 mb-4" />
              <p className="text-gray-500 text-lg">No tienes favoritos aún.</p>
              <button onClick={() => navigateTo('explorar')} className="mt-4 bg-upec-green text-white px-6 py-2 rounded-lg hover:bg-green-600 transition">Explorar productos</button>
            </div>
          ) : (
            <ProductGrid 
              products={products.filter(p => favorites.includes(p.id))} 
              favorites={favorites} 
              toggleFavorite={toggleFavorite} 
              handlePayment={handlePayment}
              addToCart={addToCart}
              approveProduct={approveProduct}
              rejectProduct={rejectProduct}
              setShowProductDetail={openProductDetail}
              getAverageRating={getAverageRating}
              reviews={reviews}
            />
          )}
        </section>
      )}

      {/* PÁGINA: CARRITO */}
      {currentPage === 'carrito' && (
        <section className="container mx-auto px-4 py-8">
          <h3 className="text-2xl font-bold mb-6">Carrito de compras ({cart.length})</h3>
          {cart.length === 0 ? (
            <div className="text-center py-20 bg-white rounded-xl shadow-sm">
              <ShoppingCart size={48} className="mx-auto text-gray-300 mb-4" />
              <p className="text-gray-500 text-lg">Tu carrito está vacío.</p>
            </div>
          ) : (
            <div className="bg-white rounded-xl shadow-sm p-6">
              {cart.map(item => (
                <div key={item.id} className="flex items-center gap-4 border-b border-gray-100 py-4 last:border-0">
                  <img src={item.image} alt={item.title} className="w-20 h-20 object-cover rounded-lg" />
                  <div className="flex-1">
                    <h4 className="font-bold">{item.title}</h4>
                    <p className="text-sm text-gray-500">{item.seller}</p>
                  </div>
                  <p className="font-bold text-lg">${item.price.toFixed(2)}</p>
                  <button onClick={() => removeFromCart(item.id)} className="text-red-500 hover:text-red-700">
                    <Trash2 size={20} />
                  </button>
                </div>
              ))}
              <div className="mt-6 pt-6 border-t border-gray-200 flex justify-between items-center">
                <span className="text-lg font-bold">Total: ${cart.reduce((sum, item) => sum + item.price, 0).toFixed(2)}</span>
                <button onClick={() => handlePayment(cart[0])} className="bg-upec-green text-white px-6 py-3 rounded-lg font-bold hover:bg-green-600 transition">Proceder al pago</button>
              </div>
            </div>
          )}
        </section>
      )}

      {/* PÁGINA: MIS PUBLICACIONES */}
      {currentPage === 'mis-publicaciones' && (
        <section className="container mx-auto px-4 py-8">
          <h3 className="text-2xl font-bold mb-6">Mis publicaciones</h3>
          {userRole !== 'vendedor' ? (
            <div className="text-center py-20 bg-white rounded-xl shadow-sm">
              <FileText size={48} className="mx-auto text-gray-300 mb-4" />
              <p className="text-gray-500 text-lg">Cambia a Modo Vendedor para ver tus publicaciones.</p>
              <button onClick={switchRole} className="mt-4 bg-upec-green text-white px-6 py-2 rounded-lg hover:bg-green-600 transition">Cambiar a Vendedor</button>
            </div>
          ) : (
            <ProductGrid 
              products={myProducts}
              favorites={favorites} 
              toggleFavorite={toggleFavorite} 
              handlePayment={handlePayment}
              addToCart={addToCart}
              approveProduct={approveProduct}
              rejectProduct={rejectProduct}
              setShowProductDetail={openProductDetail}
              showAdminControls={userRole === 'admin'}
              getAverageRating={getAverageRating}
              reviews={reviews}
            />
          )}
        </section>
      )}

      {/* PÁGINA: AYUDA */}
      {currentPage === 'ayuda' && (
        <section className="container mx-auto px-4 py-8 max-w-3xl">
          <h3 className="text-2xl font-bold mb-6">Centro de ayuda</h3>
          <div className="space-y-4">
            {[
              { q: '¿Cómo me registro?', a: 'Crea una cuenta con tu nombre, correo @upec.edu.ec y una contraseña segura. Se valida el dominio institucional.' },
              { q: '¿Cómo publico un producto?', a: 'Cambia a Modo Vendedor en el header y haz clic en "Publicar". El producto pasará por revisión de un administrador antes de publicarse.' },
              { q: '¿Cuál es la comisión?', a: 'UPEC Market retiene un 3% del valor de cada venta para mantener la plataforma.' },
              { q: '¿Cómo funciona la revisión física?', a: 'Un administrador verifica el producto en un punto del campus. Si pasa, se publica y recibes una notificación.' },
              { q: '¿Cómo compro?', a: 'Inicia sesión, agrega productos al carrito y procede al pago seguro con Stripe.' },
              { q: '¿Cómo dejo una reseña?', a: 'Después de que Stripe confirme la compra, puedes calificar el producto con estrellas y dejar un comentario.' },
            ].map((item, i) => (
              <div key={i} className="bg-white p-6 rounded-xl shadow-sm">
                <h4 className="font-bold mb-2">{item.q}</h4>
                <p className="text-gray-600 text-sm">{item.a}</p>
              </div>
            ))}
          </div>
        </section>
      )}

      {currentPage === 'compras' && (
        <section className="container mx-auto px-4 py-8">
          <h3 className="text-2xl font-bold mb-6">Mis compras</h3>
          {orders.length === 0 ? <p className="bg-white p-8 rounded-xl text-gray-500">Todavía no tienes compras.</p> : (
            <div className="space-y-3">
              {orders.map(order => (
                <div key={order.id} className="bg-white rounded-xl shadow-sm p-4 flex flex-wrap items-center gap-4">
                  <img src={order.image || 'https://images.unsplash.com/photo-1560343090-f0409e92791a?auto=format&fit=crop&q=80&w=400'} alt={order.product} className="w-16 h-16 rounded-lg object-cover" />
                  <div className="flex-1"><h4 className="font-bold">{order.product}</h4><p className="text-sm text-gray-500">{order.created_at ? new Date(order.created_at).toLocaleDateString('es-EC') : ''}</p></div>
                  <span className="font-bold">${order.total.toFixed(2)}</span>
                  <span className="text-sm capitalize">{order.status}</span>
                  {order.status === 'Completado' && <button onClick={() => setShowReviewModal({ id: order.product_id, title: order.product })} className="text-sm text-upec-green font-medium">Dejar reseña</button>}
                </div>
              ))}
            </div>
          )}
        </section>
      )}

      {currentPage === 'admin' && (
        <section className="container mx-auto px-4 py-8">
          {userRole === 'admin' ? (
            <>
              <h3 className="text-2xl font-bold mb-6">Productos pendientes de revisión</h3>
              <ProductGrid
                products={adminProducts}
                favorites={favorites}
                toggleFavorite={toggleFavorite}
                handlePayment={handlePayment}
                addToCart={addToCart}
                approveProduct={approveProduct}
                rejectProduct={rejectProduct}
                setShowProductDetail={openProductDetail}
                showAdminControls
                getAverageRating={getAverageRating}
                reviews={reviews}
              />
            </>
          ) : (
            <div className="bg-white rounded-xl border border-gray-200 p-6">
              <h3 className="text-2xl font-bold mb-2">Panel de moderación</h3>
              <p className="text-gray-600">Tu cuenta no tiene el rol de administrador. Solicita a una persona autorizada que asigne el rol <code>admin</code> a tu correo institucional; después, cierra sesión e inicia sesión nuevamente.</p>
            </div>
          )}
        </section>
      )}

      {/* MODAL: MENSAJES GENERALES */}
      {showMessages && (
        <div className="fixed inset-0 bg-black/50 z-[80] flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden flex flex-col">
            <div className="p-4 border-b border-gray-200 flex justify-between items-center">
              <h3 className="text-xl font-bold flex items-center gap-2"><MessageSquare size={20} /> Mensajes</h3>
              <button onClick={() => setShowMessages(false)} className="text-gray-500 hover:text-gray-800"><X size={24} /></button>
            </div>
            <div className="flex-1 overflow-y-auto">
              {messages.map(msg => (
                <button onClick={() => openConversation(msg)} key={msg.id} className={`w-full text-left p-4 border-b border-gray-100 cursor-pointer hover:bg-gray-50 ${msg.unread ? 'bg-blue-50' : ''}`}>
                  <div className="flex justify-between">
                    <h4 className="font-bold text-sm">{msg.other_user}</h4>
                    <span className="text-xs text-gray-400">{msg.unread ? `${msg.unread} sin leer` : ''}</span>
                  </div>
                  <p className="text-xs text-gray-500 mt-1">Re: {msg.title}</p>
                  <p className="text-sm mt-2">{msg.last_message || 'Conversación iniciada'}</p>
                </button>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* MODAL: DETALLE PRODUCTO + RESEÑAS */}
      {showProductDetail && (
        <div className="fixed inset-0 bg-black/50 z-[80] flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-3xl my-8 overflow-hidden max-h-[90vh] flex flex-col">
            <div className="relative h-64 shrink-0">
              <img src={showProductDetail.image || 'https://images.unsplash.com/photo-1560343090-f0409e92791a?auto=format&fit=crop&q=80&w=400'} alt={showProductDetail.title} className="w-full h-full object-cover" />
              <button onClick={() => setShowProductDetail(null)} className="absolute top-4 right-4 bg-white/90 rounded-full p-2 hover:bg-white"><X size={20} /></button>
            </div>
            <div className="p-6 overflow-y-auto">
              <div className="flex justify-between items-start mb-2">
                <h3 className="text-2xl font-bold">{showProductDetail.title}</h3>
                <div className="flex items-center gap-2">
                  <StarRating rating={Math.round(parseFloat(getAverageRating(showProductDetail.id)))} size={20} />
                  <span className="text-sm font-medium">{getAverageRating(showProductDetail.id)} ({(reviews[showProductDetail.id] || []).length || showProductDetail.review_count || 0})</span>
                </div>
              </div>
              <p className="text-sm text-gray-500 mb-4">{showProductDetail.category} • Vendedor: {showProductDetail.seller}</p>
              <p className="text-gray-700 mb-4">{showProductDetail.description}</p>
              <p className="text-3xl font-bold text-upec-dark mb-4">${showProductDetail.price.toFixed(2)}</p>

              <div className="flex gap-3 mb-6">
                <button onClick={() => { addToCart(showProductDetail); setShowProductDetail(null); }} className="flex-1 bg-upec-green text-white py-3 rounded-lg font-bold hover:bg-green-600 transition">Agregar al carrito</button>
                <button onClick={() => { handlePayment(showProductDetail); setShowProductDetail(null); }} className="flex-1 bg-upec-dark text-white py-3 rounded-lg font-bold hover:bg-gray-800 transition">Comprar ahora</button>
                <button 
                  onClick={() => { startConversation(showProductDetail); setShowProductDetail(null); }}
                  className="bg-blue-500 text-white px-4 py-3 rounded-lg font-bold hover:bg-blue-600 transition flex items-center gap-2"
                  title="Contactar vendedor"
                >
                  <MessageSquare size={20} /> Chat
                </button>
              </div>

              {/* SECCIÓN RESEÑAS */}
              <div className="border-t border-gray-200 pt-6">
                <h4 className="text-lg font-bold mb-4">Reseñas de compradores</h4>
                {(reviews[showProductDetail.id] || []).length === 0 ? (
                  <p className="text-sm text-gray-500">Aún no hay reseñas. ¡Sé el primero en opinar!</p>
                ) : (
                  <div className="space-y-4">
                    {(reviews[showProductDetail.id] || []).map(review => (
                      <div key={review.id} className="bg-gray-50 p-4 rounded-lg">
                        <div className="flex justify-between items-center mb-2">
                          <div className="flex items-center gap-2">
                            <div className="bg-upec-green text-white w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold">{review.user.charAt(0)}</div>
                            <span className="font-bold text-sm">{review.user}</span>
                          </div>
                          <span className="text-xs text-gray-500">{review.date}</span>
                        </div>
                        <StarRating rating={review.rating} size={14} />
                        <p className="text-sm text-gray-700 mt-2">{review.comment}</p>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* MODAL: CHAT EN TIEMPO REAL */}
      {showChatModal && (
        <div className="fixed inset-0 bg-black/50 z-[90] flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md h-[600px] flex flex-col overflow-hidden">
            <div className="p-4 bg-upec-dark text-white flex justify-between items-center">
              <div className="flex items-center gap-3">
                <div className="bg-upec-green w-10 h-10 rounded-full flex items-center justify-center">
                  <User size={20} />
                </div>
                <div>
                  <h4 className="font-bold">{showChatModal.seller}</h4>
                  <p className="text-xs text-gray-300 flex items-center gap-1">
                          Mensajes guardados
                  </p>
                </div>
              </div>
              <button onClick={() => setShowChatModal(null)} className="hover:bg-white/20 rounded-full p-1"><X size={20} /></button>
            </div>

            <div className="bg-gray-100 p-2 text-xs text-center text-gray-600">
              Conversación sobre: <span className="font-bold">{showChatModal.title}</span>
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50">
              {(chats[showChatModal.conversationId] || []).map(msg => (
                <div key={msg.id} className={`flex ${msg.from === 'user' ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[75%] p-3 rounded-2xl ${msg.from === 'user' ? 'bg-upec-green text-white rounded-br-sm' : 'bg-white text-gray-800 rounded-bl-sm shadow-sm'}`}>
                    <p className="text-sm">{msg.text}</p>
                    <p className={`text-[10px] mt-1 ${msg.from === 'user' ? 'text-green-100' : 'text-gray-400'}`}>{msg.time}</p>
                  </div>
                </div>
              ))}
              <div ref={chatEndRef} />
            </div>

            <div className="p-3 border-t border-gray-200 flex gap-2 bg-white">
              <input 
                type="text" 
                placeholder="Escribe un mensaje..." 
                value={newMessage}
                onChange={(e) => setNewMessage(e.target.value)}
                onKeyPress={(e) => e.key === 'Enter' && handleSendMessage(showChatModal.conversationId)}
                className="flex-1 border border-gray-300 rounded-full px-4 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green text-sm"
              />
              <button 
                onClick={() => handleSendMessage(showChatModal.conversationId)}
                className="bg-upec-green text-white p-3 rounded-full hover:bg-green-600 transition"
              >
                <Send size={18} />
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL: DEJAR RESEÑA */}
      {showReviewModal && (
        <div className="fixed inset-0 bg-black/50 z-[90] flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
            <h3 className="text-xl font-bold mb-4 flex items-center gap-2"><Star className="text-yellow-400" /> Deja tu reseña</h3>
            <p className="text-sm text-gray-500 mb-4">Producto: <span className="font-bold">{showReviewModal.title}</span></p>

            <form onSubmit={handleAddReview} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-2">Calificación</label>
                <StarRatingInput />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Comentario</label>
                <textarea 
                  name="comment" 
                  rows="3" 
                  required
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green" 
                  placeholder="Cuéntanos tu experiencia con este producto..."
                ></textarea>
              </div>
              <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={() => setShowReviewModal(null)} className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">Omitir</button>
                <button type="submit" className="px-4 py-2 bg-upec-green text-white rounded-lg hover:bg-green-600 transition font-medium">Publicar reseña</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL PUBLICAR */}
      {showPublishModal && (
        <div className="fixed inset-0 bg-black/50 z-[80] flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
            <h3 className="text-xl font-bold mb-4">Publicar nuevo producto</h3>
            <form onSubmit={handlePublish} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1">Título del producto</label>
                <input name="title" required className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green" placeholder="Ej. Calculadora científica" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Descripción</label>
                <textarea name="description" rows="2" className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green" placeholder="Describe el estado del producto..."></textarea>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Precio base ($)</label>
                <input name="price" type="number" min="0.01" step="0.01" required className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green" placeholder="Ej. 20.00" />
                <p className="text-xs text-gray-500 mt-1">La comisión del 3% se calcula al concretar la venta.</p>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Categoría</label>
                <select name="category_id" required className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green">
                  {categoryList.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Modalidad</label>
                <select name="type" className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green">
                  <option value="En venta">En venta</option>
                  <option value="En intercambio">En intercambio</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Imagen del producto (opcional)</label>
                <input name="image" type="file" accept="image/jpeg,image/png,image/webp" className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-upec-green file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1" />
                <p className="text-xs text-gray-500 mt-1">JPG, PNG o WebP; máximo 5 MB.</p>
              </div>
              <div className="bg-yellow-50 border border-yellow-200 p-3 rounded-lg text-xs text-yellow-800">
                <strong>Nota:</strong> Un administrador revisará el producto antes de publicarlo.
              </div>
              <div className="flex justify-end gap-2 pt-2">
                <button type="button" disabled={isPublishing} onClick={() => setShowPublishModal(false)} className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition disabled:opacity-50">Cancelar</button>
                <button type="submit" disabled={isPublishing} className="px-4 py-2 bg-upec-green text-white rounded-lg hover:bg-green-600 transition font-medium disabled:opacity-50">{isPublishing ? 'Guardando...' : 'Enviar a revisión'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL PAGO */}
      {showPaymentModal && (
        <div className="fixed inset-0 bg-black/50 z-[80] flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
            <h3 className="text-xl font-bold mb-4 flex items-center gap-2"><CreditCard className="text-upec-green" /> Pago seguro con Stripe</h3>
            <div className="bg-gray-50 p-4 rounded-lg mb-4 border border-gray-200">
              <p className="text-sm font-medium text-gray-500 mb-1">Producto:</p>
              <p className="font-bold">{showPaymentModal.product.title}</p>
              <div className="mt-4 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-600">Precio final:</span>
                  <span className="font-bold">${showPaymentModal.total.toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-red-500">
                  <span>Comisión (3%):</span>
                  <span>-${showPaymentModal.commission.toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-green-600 font-bold border-t border-gray-300 pt-2">
                  <span>Vendedor recibe:</span>
                  <span>${showPaymentModal.sellerReceives.toFixed(2)}</span>
                </div>
              </div>
            </div>
            <p className="text-xs text-gray-500 mb-4">El pago se confirma mediante Stripe. La liquidación al vendedor se administra desde la cuenta de la plataforma; no se transfiere automáticamente a Stripe Connect.</p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setShowPaymentModal(null)} className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">Cancelar</button>
              <button onClick={confirmPayment} className="px-4 py-2 bg-upec-green text-white rounded-lg hover:bg-green-600 transition font-medium flex items-center gap-2">
                <CreditCard size={18} /> Continuar a Stripe
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// --- SUBCOMPONENTE: INPUT DE ESTRELLAS ---
function StarRatingInput() {
  const [rating, setRating] = useState(5);
  return (
    <>
      <input type="hidden" name="rating" value={rating} />
      <div className="flex gap-1">
        {[1, 2, 3, 4, 5].map((star) => (
          <Star
            key={star}
            size={32}
            className={`cursor-pointer transition hover:scale-110 ${star <= rating ? 'fill-yellow-400 text-yellow-400' : 'text-gray-300'}`}
            onClick={() => setRating(star)}
          />
        ))}
      </div>
    </>
  );
}

// --- SUBCOMPONENTE: GRID DE PRODUCTOS ---
function ProductGrid({ products, favorites, toggleFavorite, handlePayment, addToCart, approveProduct, rejectProduct, setShowProductDetail, showAdminControls = false, getAverageRating, reviews }) {
  if (products.length === 0) {
    return (
      <div className="container mx-auto px-4 pb-20 text-center py-10 bg-white rounded-xl shadow-sm">
        <p className="text-gray-500">No hay productos para mostrar.</p>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-4 pb-20">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        {products.map((prod) => {
          const avgRating = getAverageRating ? getAverageRating(prod.id) : 0;
          const reviewCount = (reviews && reviews[prod.id] || []).length || prod.review_count || 0;
          
          return (
            <div key={prod.id} className="bg-white rounded-xl shadow-sm hover:shadow-lg transition overflow-hidden border border-gray-100 flex flex-col">
              <div className="relative h-48 bg-gray-200 cursor-pointer" onClick={() => setShowProductDetail(prod)}>
                <img src={prod.image || 'https://images.unsplash.com/photo-1560343090-f0409e92791a?auto=format&fit=crop&q=80&w=400'} alt={prod.title} className="w-full h-full object-cover" />
                <span className={`absolute top-2 left-2 text-xs font-bold px-2 py-1 rounded text-white ${prod.type === 'En venta' ? 'bg-upec-green' : 'bg-blue-500'}`}>{prod.type}</span>
                {prod.status === 'Pendiente' && (
                  <span className="absolute top-2 right-2 bg-yellow-500 text-white text-xs px-2 py-1 rounded font-bold">En revisión</span>
                )}
                {prod.status === 'Aprobado' && (
                  <span className="absolute top-2 right-2 bg-blue-600 text-white text-xs px-2 py-1 rounded font-bold flex items-center gap-1"><ShieldCheck size={12} /> Verificado</span>
                )}
              </div>
              <div className="p-4 flex-1 flex flex-col justify-between">
                <div>
                  <h4 className="font-bold text-sm mb-1 line-clamp-2 cursor-pointer hover:text-upec-green" onClick={() => setShowProductDetail(prod)}>{prod.title}</h4>
                  <p className="text-xs text-gray-500 mb-2">{prod.category}</p>
                  
                  {reviewCount > 0 && (
                    <div className="flex items-center gap-1 mb-2">
                      <Star size={14} className="fill-yellow-400 text-yellow-400" />
                      <span className="text-xs font-medium">{avgRating}</span>
                      <span className="text-xs text-gray-400">({reviewCount})</span>
                    </div>
                  )}
                  
                  <p className="text-lg font-bold text-upec-dark">${prod.price.toFixed(2)}</p>
                </div>
                <div className="flex justify-between items-center mt-3 pt-3 border-t border-gray-100">
                  <span className="text-xs text-gray-500 flex items-center gap-1"><MapPin size={12} /> Tulcán, UPEC</span>
                  <button onClick={() => toggleFavorite(prod.id)} className="transition">
                    <Heart size={18} className={favorites.includes(prod.id) ? 'fill-red-500 text-red-500' : 'text-gray-400 hover:text-red-500'} />
                  </button>
                </div>
                {prod.status === 'Aprobado' && (
                  <div className="flex gap-2 mt-3">
                    <button onClick={() => addToCart(prod)} className="flex-1 bg-gray-100 text-gray-700 text-xs py-2 rounded-lg hover:bg-gray-200 transition">
                      + Carrito
                    </button>
                    <button onClick={() => handlePayment(prod)} className="flex-1 bg-upec-dark text-white text-xs py-2 rounded-lg hover:bg-gray-800 transition flex items-center justify-center gap-1">
                      <CreditCard size={14} /> Comprar
                    </button>
                  </div>
                )}
                {showAdminControls && prod.status === 'Pendiente' && (
                  <div className="mt-2 flex gap-2">
                    <button onClick={() => approveProduct(prod.id)} className="flex-1 bg-blue-500 text-white text-xs py-2 rounded hover:bg-blue-600 transition">
                      Aprobar
                    </button>
                    <button onClick={() => rejectProduct(prod.id)} className="flex-1 bg-red-500 text-white text-xs py-2 rounded hover:bg-red-600 transition">
                      Rechazar
                    </button>
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}