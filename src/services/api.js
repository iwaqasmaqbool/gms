// src/services/api.js
const API_URL = process.env.REACT_APP_API_URL || 'https://raw.gatvia.com/api';

// Example API service
const apiService = {
  // Get data from API
  async getData(endpoint) {
    try {
      const response = await fetch(`${API_URL}/${endpoint}`);
      return await response.json();
    } catch (error) {
      console.error('API request failed:', error);
      throw error;
    }
  },
  
  // Post data to API
  async postData(endpoint, data) {
    try {
      const response = await fetch(`${API_URL}/${endpoint}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      });
      return await response.json();
    } catch (error) {
      console.error('API request failed:', error);
      throw error;
    }
  }
  
  // Example usage in a component
import apiService from '../services/api';

// In a React component
function ProductList() {
  const [products, setProducts] = useState([]);
  
  useEffect(() => {
    const fetchProducts = async () => {
      try {
        const data = await apiService.getData('products');
        setProducts(data);
      } catch (error) {
        console.error('Failed to fetch products:', error);
      }
    };
    
    fetchProducts();
  }, []);
};

export default apiService;