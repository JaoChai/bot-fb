import { createBrowserRouter } from "react-router"
import { RootLayout } from "@/components/layout/RootLayout"
import { HomePage } from "@/pages/HomePage"

export const router = createBrowserRouter([
  {
    path: "/",
    element: <RootLayout />,
    children: [
      {
        index: true,
        element: <HomePage />,
      },
    ],
  },
])
